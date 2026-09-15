<?php

namespace App\Support\Addon;

use App\Admin\Models\Menu;
use App\Support\Addon\Contracts\Lifecycle;
use App\Support\Addon\Models\Addon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * 插件生命周期编排（§6.3）与菜单/权限注入清除（§6.5）。
 * 注册表（addons 表）唯一写入口；AddonManager 保持只读。
 */
class AddonInstaller
{
    public function __construct(protected AddonManager $manager)
    {
    }

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
        foreach ($info->dependencies as $dep) {
            if (Addon::find($dep) === null) {
                throw new AddonException("依赖插件 [{$dep}] 未安装");
            }
        }

        Artisan::call('migrate', ['--path' => $info->migrationPath(), '--realpath' => true, '--force' => true]);
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
        $this->finish($info);

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
        if (! $keepData) {
            Artisan::call('migrate:rollback', [
                '--path' => $info->migrationPath(), '--realpath' => true, '--force' => true,
            ]);
        }
        $this->hook($info, 'uninstall');
        Menu::where('addon_key', $name)->delete();
        $this->deletePermissions($name);
        $record->delete();
        $this->manager->flushCompiled();
        $this->refreshFrameworkCaches();
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
        foreach ($info->dependencies as $dep) {
            $depRecord = Addon::find($dep);
            if ($depRecord === null || ! $depRecord->enabled) {
                throw new AddonException("依赖插件 [{$dep}] 未安装或未启用，无法启用 [{$name}]");
            }
        }
        $record->update(['enabled' => true]);
        $this->hook($info, 'enable');
        $this->finish($info);
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
        $record->update(['enabled' => false]);
        $this->hook($info, 'disable');
        $this->detachListeners($info);
        $this->manager->flushCompiled();
        $this->refreshFrameworkCaches();
    }

    protected function mustExistOnDisk(string $name): AddonInfo
    {
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

    protected function hook(AddonInfo $info, string $method): void
    {
        $class = $info->addonClass();
        if (class_exists($class)) {
            $addon = app($class);
            if ($addon instanceof Lifecycle) {
                $addon->{$method}();
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
        foreach (array_keys($listen) as $event) {
            // v1 框架事件尚无自有监听者，按事件整体摘除；引入自有监听者时改为逐监听器摘除
            Event::forget($event);
        }
    }

    /** 安装/启用收尾：冲编译缓存、按需重建框架缓存、请求进程内即时注册 provider */
    protected function finish(AddonInfo $info): void
    {
        $this->manager->flushCompiled();
        $this->refreshFrameworkCaches();
        if (app()->isBooted() && class_exists($info->providerClass())) {
            app()->register($info->providerClass());
        }
    }

    /** §11 风险对策：config/route/event 缓存在用时自动重建，保证装/停/卸即时可见 */
    protected function refreshFrameworkCaches(): void
    {
        $cachePath = base_path('bootstrap/cache');
        if (is_file($cachePath.'/config.php')) {
            Artisan::call('config:cache');
        }
        if (is_file($cachePath.'/events.php')) {
            Artisan::call('event:cache');
        }
        if (glob($cachePath.'/routes-*.php')) {
            Artisan::call('route:cache');
        }
    }
}
