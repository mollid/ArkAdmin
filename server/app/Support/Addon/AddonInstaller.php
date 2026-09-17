<?php

namespace App\Support\Addon;

use App\Admin\Models\Menu;
use App\Support\Addon\Contracts\Lifecycle;
use App\Support\Addon\Models\Addon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * 插件生命周期编排（§6.3）与菜单/权限注入清除（§6.5）。
 * 注册表（addons 表）唯一写入口；AddonManager 保持只读。
 */
class AddonInstaller
{
    public function __construct(
        protected AddonManager $manager,
        protected AddonDependency $dependencies,
        protected FrameworkCache $frameworkCache,
    ) {}

    /** 安装：校验 → 迁移 → 注册（启用态）→ 菜单 → 权限 → install 钩子 */
    public function install(string $name): Addon
    {
        $info = $this->mustExistOnDisk($name);
        if (Addon::find($name) !== null) {
            throw new AddonException("插件 [{$name}] 已安装");
        }
        if (! $info->isSupported((string) config('arkadmin.version'))) {
            throw new AddonException(
                "插件 [{$name}] 要求框架版本 >= {$info->supportVersion}，当前为 ".config('arkadmin.version')
            );
        }
        $this->dependencies->assertInstallable($info);

        Artisan::call('migrate', ['--path' => $info->migrationPath(), '--realpath' => true, '--force' => true]);
        // 迁移在事务外：失败自愈（重试时 migrate 幂等空转）；其后任一步失败，
        // 注册表随事务回滚，避免留下"已注册但无菜单/权限"的半安装态（重装被"已安装"挡死）
        $record = DB::transaction(function () use ($info) {
            $record = Addon::create([
                'name' => $info->name,
                'title' => $info->title,
                'version' => $info->version,
                'enabled' => true,
                'install_time' => now(),
            ]);
            $permissions = $this->syncMenus($info);
            $this->createPermissions($info, array_values(array_unique(array_merge(
                $permissions, $this->declaredPermissions($info)
            ))));
            $this->hook($info, 'install');

            return $record;
        });
        $this->finish($info);
        $this->syncFrontend($info);

        return $record;
    }

    /** 卸载：业务表回滚（--keep-data 可保留）→ uninstall 钩子 → 清菜单/权限/注册行 */
    public function uninstall(string $name, bool $keepData = false): void
    {
        $record = Addon::find($name);
        if ($record === null) {
            throw new AddonException("插件 [{$name}] 未安装");
        }
        if ($record->enabled) {
            throw new AddonException("插件 [{$name}] 处于启用状态，请先执行 addon:disable");
        }
        $info = $this->mustExistOnDisk($name);
        // 反向依赖保护（enabledOnly=false：禁用态依赖方也挡，卸载顺序=先卸依赖方）
        $deps = $this->dependencies->dependents($name, false);
        if ($deps !== []) {
            throw new AddonException('插件 ['.$name.'] 正被已安装插件 ['.implode('、', $deps).'] 依赖，请先卸载它们');
        }
        if (! $keepData) {
            // migrate:reset 而非 rollback：rollback 只作用于最后一批迁移（getLast()），
            // 后装的其它插件/框架迁移会把本插件挤出最后一批，导致回滚静默空转
            Artisan::call('migrate:reset', [
                '--path' => $info->migrationPath(), '--realpath' => true, '--force' => true,
            ]);
        }
        $this->hook($info, 'uninstall');
        Menu::where('addon_key', $name)->delete();
        $this->deletePermissions($name);
        $record->delete();
        $this->purgeFrontend($name);
        $this->manager->flushCompiled();
        $this->frameworkCache->rebuild();
    }

    public function enable(string $name): void
    {
        $record = Addon::find($name);
        if ($record === null) {
            throw new AddonException("插件 [{$name}] 未安装");
        }
        if ($record->enabled) {
            throw new AddonException("插件 [{$name}] 已是启用状态");
        }
        $info = $this->mustExistOnDisk($name);
        // 框架升/降级后启用态插件复核 support_version（此前只在 install 校验一次）
        if (! $info->isSupported((string) config('arkadmin.version'))) {
            throw new AddonException(
                "插件 [{$name}] 要求框架版本 >= {$info->supportVersion}，当前为 ".config('arkadmin.version')
            );
        }
        $this->dependencies->assertEnableable($info);
        $record->update(['enabled' => true]);
        $this->hook($info, 'enable');
        $this->finish($info);
        // 幂等补齐前端产物：手工清理过或插件源更新过时自愈（§9 验收 5“完整恢复”）
        $this->syncFrontend($info);
    }

    public function disable(string $name): void
    {
        $record = Addon::find($name);
        if ($record === null) {
            throw new AddonException("插件 [{$name}] 未安装");
        }
        if (! $record->enabled) {
            throw new AddonException("插件 [{$name}] 已是禁用状态");
        }
        $info = $this->mustExistOnDisk($name);
        // 反向依赖保护：只看已启用的依赖方（禁用态依赖方不阻塞禁用）
        $deps = $this->dependencies->dependents($name);
        if ($deps !== []) {
            throw new AddonException('插件 ['.$name.'] 正被已启用插件 ['.implode('、', $deps).'] 依赖，请先禁用它们');
        }
        $record->update(['enabled' => false]);
        $this->hook($info, 'disable');
        $this->detachListeners($info);
        $this->manager->flushCompiled();
        $this->frameworkCache->rebuild();
    }

    /** 最近一次前端同步的目标路径；null 表示无前端或同步失败（命令据此决定是否提示构建） */
    public ?string $lastSyncedFrontend = null;

    /**
     * ark:crud 收尾（§10 M5）：把生成器追加进 menus.php/permissions.php 的内容写入 DB
     * 并自愈前端产物。幂等：syncMenus 是 updateOrCreate、createPermissions 是 firstOrCreate；
     * 仅限已安装插件（未安装时菜单/权限由 install 流程负责）。
     */
    public function refreshMenusAndPermissions(string $name): void
    {
        $record = Addon::find($name);
        if ($record === null) {
            throw new AddonException("插件 [{$name}] 未安装，无法刷新菜单与权限");
        }
        $info = $this->mustExistOnDisk($name);
        $permissions = $this->syncMenus($info);
        $this->createPermissions($info, array_values(array_unique(array_merge(
            $permissions, $this->declaredPermissions($info)
        ))));
        $this->syncFrontend($info);
    }

    /** 插件前端产物目录：<admin_path>/src/addons/<name> */
    public function frontendDir(string $addonName): string
    {
        return rtrim(trim((string) config('arkadmin.admin_path')), '/').'/src/addons/'.$addonName;
    }

    /**
     * §6.6：addons/<key>/admin/ → admin/src/addons/<key>/。
     * 先拷贝到同分区临时目录再整体替换：拷贝阶段失败时既有产物原样保留（不出现半拷贝前端）；
     * 任何失败都记警告并返回 null，绝不谎报"已同步"。
     */
    protected function syncFrontend(AddonInfo $info): ?string
    {
        $this->lastSyncedFrontend = null;
        $src = $info->dir.'/admin';
        if (! is_dir($src)) {
            return null;
        }
        $adminPath = rtrim(trim((string) config('arkadmin.admin_path')), '/');
        // 空值/不指向真实后台目录一律拒绝：否则 mkdir -p 会在文件系统某处造出幻影 admin 树
        if ($adminPath === '' || ! is_dir($adminPath.'/src')) {
            logger()->warning("插件 [{$info->name}] 前端未同步：arkadmin.admin_path 无效（{$adminPath}）");

            return null;
        }
        $dest = $this->frontendDir($info->name);
        $parent = dirname($dest);
        if (! is_dir($parent) && ! @mkdir($parent, 0777, true)) {
            logger()->warning("插件 [{$info->name}] 前端未同步：{$parent} 无法创建");

            return null;
        }
        if (! is_writable($parent)) {
            logger()->warning("插件 [{$info->name}] 前端未同步：{$parent} 不可写");

            return null;
        }
        $tmp = $dest.'.tmp-'.getmypid();
        $this->deleteDir($tmp);
        if (! @mkdir($tmp, 0777, true) || ! $this->copyTree($src, $tmp)) {
            logger()->warning("插件 [{$info->name}] 前端拷贝失败，已保留既有产物");
            $this->deleteDir($tmp);

            return null;
        }
        // 原子替换：旧目录改名让位（不删内容）→ 新目录就位 → 成功后清旧目录。
        // 任一步失败都能保留/回滚出完整的旧副本，杜绝半拷贝或半删除状态
        $backup = $dest.'.bak-'.getmypid();
        $this->deleteDir($backup);
        if (is_dir($dest) && ! @rename($dest, $backup)) {
            logger()->warning("插件 [{$info->name}] 前端替换失败：无法让位旧产物，请检查 admin_path 权限");
            $this->deleteDir($tmp);

            return null;
        }
        if (! @rename($tmp, $dest)) {
            logger()->warning("插件 [{$info->name}] 前端替换失败，已回滚旧产物");
            if (is_dir($backup)) {
                @rename($backup, $dest);
            }
            $this->deleteDir($tmp);

            return null;
        }
        $this->deleteDir($backup);
        $this->lastSyncedFrontend = $dest;

        return $dest;
    }

    protected function copyTree(string $src, string $dest): bool
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($items as $item) {
            $target = $dest.'/'.$items->getSubPathName();
            if ($item->isDir()) {
                if (! is_dir($target) && ! @mkdir($target, 0777, true)) {
                    return false;
                }
            } elseif (! @copy($item->getPathname(), $target)) {
                return false;
            }
        }

        return true;
    }

    protected function purgeFrontend(string $addonName): void
    {
        $this->deleteDir($this->frontendDir($addonName));
    }

    protected function deleteDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }

    protected function mustExistOnDisk(string $name): AddonInfo
    {
        // 纵深防御：AddonInfo 校验的是清单 name 字段且只对比 basename，
        // 不挡 "../sibling" 这类路径拼接——目录定位前先按插件名正则卡死
        if (! preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
            throw new AddonException("非法插件名 [{$name}]");
        }
        try {
            return AddonInfo::fromDir($this->manager->addonPath().'/'.$name);
        } catch (AddonException $e) {
            throw new AddonException("插件 [{$name}] 不存在或清单无效：".$e->getMessage(), 0, $e);
        }
    }

    /** @return array<string> 菜单中声明的权限串；addon_key 强制以目录为准（§6.5） */
    protected function syncMenus(AddonInfo $info): array
    {
        $file = $info->menusFile();
        if (! is_file($file)) {
            return [];
        }
        $permissions = [];
        $write = function (array $node, int $parentId) use (&$write, $info, &$permissions): void {
            foreach (['name', 'title'] as $field) {
                if (! isset($node[$field]) || $node[$field] === '') {
                    throw new AddonException("插件 [{$info->name}] 菜单定义缺少字段 {$field}");
                }
            }
            // menus.name 全局唯一：已被系统或其它插件占用的名字必须拒绝，
            // 否则 updateOrCreate 会改写他人菜单（addon_key 换主），卸载时再把它删掉
            $occupied = Menu::where('name', $node['name'])
                ->where('addon_key', '!=', $info->name)
                ->exists();
            if ($occupied) {
                throw new AddonException("插件 [{$info->name}] 菜单名 [{$node['name']}] 已被系统或其它插件占用");
            }
            $menu = Menu::updateOrCreate(['name' => $node['name']], [
                'parent_id' => $parentId,
                'title' => $node['title'],
                'icon' => $node['icon'] ?? '',
                'route_path' => $node['route_path'] ?? '',
                'view_path' => $node['view_path'] ?? '',
                'permission' => $node['permission'] ?? '',
                'addon_key' => $info->name,
                'sort' => $node['sort'] ?? 0,
                'is_show' => $node['is_show'] ?? true,
            ]);
            if (($node['permission'] ?? '') !== '') {
                $permissions[] = $node['permission'];
            }
            foreach ($node['children'] ?? [] as $child) {
                $write($child, (int) $menu->id);
            }
        };
        foreach ((array) require $file as $node) {
            if (is_array($node)) {
                $write($node, 0);
            }
        }

        return $permissions;
    }

    /** @return array<string> database/permissions.php 声明的额外权限（如按钮级动作） */
    protected function declaredPermissions(AddonInfo $info): array
    {
        $file = $info->permissionsFile();
        if (! is_file($file)) {
            return [];
        }
        $list = require $file;
        if (! is_array($list)) {
            throw new AddonException("插件 [{$info->name}] permissions.php 必须返回字符串数组");
        }

        return array_values(array_filter($list, 'is_string'));
    }

    protected function createPermissions(AddonInfo $info, array $names): void
    {
        foreach ($names as $permission) {
            Permission::firstOrCreate(
                ['name' => $permission, 'guard_name' => 'admin'],
                ['module' => $info->name]
            );
        }
        // RbacSeeder 语义（超管 = 全部权限）在插件安装时的延续：接口层有 Gate::before 旁路，
        // 但前端 has() 是字符串包含检查，不授予则按钮级权限失效。
        // 增量授予而非全量 syncPermissions——不覆盖运维对超管角色的手工回收
        $super = Role::where('name', config('arkadmin.super_role', 'super_admin'))
            ->where('guard_name', 'admin')->first();
        if ($super !== null && $names !== []) {
            $super->givePermissionTo($names);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** 卸载权限：清 spatie 两张关联表后删权限行（§6.5 module = <key>） */
    protected function deletePermissions(string $name): void
    {
        $ids = Permission::where('module', $name)->where('guard_name', 'admin')->pluck('id');
        if ($ids->isEmpty()) {
            return;
        }
        $tables = config('permission.table_names');
        DB::table($tables['role_has_permissions'])->whereIn('permission_id', $ids)->delete();
        DB::table($tables['model_has_permissions'])->whereIn('permission_id', $ids)->delete();
        Permission::whereIn('id', $ids)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function hook(AddonInfo $info, string $method, array $args = []): void
    {
        $class = $info->addonClass();
        if (class_exists($class)) {
            $addon = app($class);
            if ($addon instanceof Lifecycle) {
                $addon->{$method}(...$args);
            }
        }
    }

    /** 禁用/卸载后摘除本进程已挂的事件监听（路由摘除以进程重启为准，测试用 remount 模拟） */
    protected function detachListeners(AddonInfo $info): void
    {
        $class = $info->providerClass();
        if (! class_exists($class)) {
            return;
        }
        $listen = (new \ReflectionClass($class))->getDefaultProperties()['listen'] ?? [];
        $events = array_keys($listen);
        if ($events === []) {
            return;
        }
        foreach ($events as $event) {
            Event::forget($event);
        }
        // Event::forget 是按事件整体摘除，会把监听同一事件的兄弟插件一起杀掉，
        // 从其它启用插件的 listen 映射重挂幸存者（本插件已 enabled=false，不在其中）
        foreach ($this->manager->enabledInfos() as $other) {
            if ($other->name === $info->name || ! class_exists($other->providerClass())) {
                continue;
            }
            $otherListen = (new \ReflectionClass($other->providerClass()))->getDefaultProperties()['listen'] ?? [];
            foreach ($events as $event) {
                foreach ((array) ($otherListen[$event] ?? []) as $listener) {
                    Event::listen($event, $listener);
                }
            }
        }
    }

    /**
     * 升级（harness §4.2）：磁盘版本 > 注册表版本（或 $force）时执行
     * 迁移 → upgrade(旧版本) 钩子 → 菜单/权限补齐 → 前端同步 → 写回注册表。
     * 各步幂等，任一步失败即抛、version 保持旧值、可安全重试。
     */
    public function upgrade(string $name, bool $force = false): ?AddonInfo
    {
        $record = Addon::find($name);
        if ($record === null) {
            throw new AddonException("插件 [{$name}] 未安装");
        }
        $info = $this->mustExistOnDisk($name);
        if (! $force && version_compare($info->version, (string) $record->version, '<=')) {
            return null;    // 已是最新；版本没 bump 但需补迁移时用 --force
        }
        if (! $info->isSupported((string) config('arkadmin.version'))) {
            throw new AddonException(
                "插件 [{$name}] 要求框架版本 >= {$info->supportVersion}，当前为 ".config('arkadmin.version')
            );
        }
        // 新版本清单可能引入新依赖甚至环：升级与安装/启用同卡口，不放过「已启用但依赖缺失」的脏状态
        $this->dependencies->assertInstallable($info);
        $from = (string) $record->version;
        Artisan::call('migrate', ['--path' => $info->migrationPath(), '--realpath' => true, '--force' => true]);
        DB::transaction(function () use ($info, $from, $record) {
            $permissions = $this->syncMenus($info);
            $this->createPermissions($info, array_values(array_unique(array_merge(
                $permissions, $this->declaredPermissions($info)
            ))));
            $this->hook($info, 'upgrade', [$from]);
            $record->update(['version' => $info->version, 'title' => $info->title]);
        });
        // 禁用态升级只落数据不动运行态：绝不能因升级把禁用插件的 provider 重新挂回进程
        $this->finish($info, $record->enabled);
        $this->syncFrontend($info);

        return $info;
    }

    /** 安装/启用收尾：冲编译缓存、按需重建框架缓存（FrameworkCache）、按 $registerProvider 即时注册 provider */
    protected function finish(AddonInfo $info, bool $registerProvider = true): void
    {
        $this->manager->flushCompiled();
        $this->frameworkCache->rebuild();
        if ($registerProvider && app()->isBooted() && class_exists($info->providerClass())) {
            try {
                app()->register($info->providerClass());
                $this->manager->markLoaded($info->providerClass());
            } catch (\Throwable $e) {
                // 与 AddonManager::boot 同策略：单个坏插件不拖垮整个后台，状态变更已落库
                logger()->warning("插件 [{$info->name}] provider 注册失败：".$e->getMessage());
            }
        }
    }
}
