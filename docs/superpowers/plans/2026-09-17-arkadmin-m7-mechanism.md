# ArkAdmin M7 机制完整化 — 实施计划

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:executing-plans。Steps 用 checkbox 跟踪。

**Goal:** 把插件机制自身补完整：插件新增迁移有执行路径（升级）、`upgrade()` 钩子真正被调用、依赖从「存在」升级为「启用且无环 + 反向保护」、插件管理 HTTP 界面、一行命令把框架声明同步到运行环境（`ark:sync`）。

**Spec:** `docs/superpowers/specs/2026-09-17-arkadmin-harness.md` §4.1–4.3（zip 打包明确不做）。

**Architecture:** 升级链 = 迁移 → `upgrade(旧版本)` 钩子 → 菜单/权限补齐 → 前端同步 → 写回注册表版本；**任一步失败即抛、version 保持旧值、各步幂等可重试**。升级是**显式动作**（`addon:upgrade` 命令与 HTTP 端点共享同一 `AddonInstaller::upgrade()`），不做 boot 期自动升级。依赖校验抽成独立组件 `AddonDependency`（图以磁盘清单为准、启用态以注册表为准）：安装只要求依赖「已安装」、启用要求「已安装且已启用」，另加环检测与反向保护（被依赖者的 disable/uninstall 拒绝）。`ark:sync` = `FrameworkSync`（RbacSeeder + MenuSeeder + 系统插件幂等安装）+ 框架缓存按需重建；后者从 `AddonInstaller` 抽成 `FrameworkCache` 供两个调用方复用。插件管理界面只管理**磁盘上已存在的插件目录**（不传 zip、不删文件）；系统插件（`config('arkadmin.system_addons')`）在 HTTP 层禁止 disable/uninstall（前端禁按钮 + 后端拦截，CLI 保留强制能力）。

**Global Constraints:** 信封恒 200（401/422 除外）、权限串 `system.addon.{index,store,update,destroy}`、`addon_key` 强制、插件菜单名全局唯一、测试隔离（fixture 走 `storage_path('framework/addon-fixture')` + `config(['arkadmin.addon_path' => ...])`）。grilling 两轮定案（用户逐项确认）：`--force` 是「版本未 bump 补跑迁移」的正规入口；钩子在迁移之后；`dependencies` 维持 `string[]` 不加版本约束；注册表不加 `upgrade_time` 列；HTTP 升级响应带 `needs_build`；GUI 自动化不可用（Chromium 缺系统库），验收 = 后端 Feature 全覆盖 + curl 实测 + 用户手工走查清单。

## File Structure

```
server/app/Support/Addon/
├── AddonDependency.php                      # 新：依赖校验（安装/启用/环检测/反向依赖）
├── FrameworkCache.php                       # 新：config/route/event 缓存按需重建（原 AddonInstaller 私有方法）
├── AddonInstaller.php                       # 改：接 AddonDependency/FrameworkCache；+ upgrade()；hook() 支持 args；enable() 补 isSupported
├── FrameworkSync.php                        # 新（App\Support\Setup 命名空间）：权限+菜单+系统插件幂等同步
├── Console/AddonUpgradeCommand.php          # 新：addon:upgrade {name?} {--all} {--force}
├── Console/ArkSyncCommand.php               # 新：ark:sync {--no-addons}
server/database/seeders/DatabaseSeeder.php   # 改：installSystemAddons() 委托 FrameworkSync
server/app/Admin/Http/Controllers/AddonController.php  # 新：插件管理 HTTP
server/routes/api.php                        # 改：+4 端点
server/app/Admin/Seeds/RbacSeeder.php        # 改：matrix + 'addon'
server/app/Admin/Seeds/MenuSeeder.php        # 改：system.addon 菜单（sort 4）
server/bootstrap/app.php                     # 改：注册 2 个新命令
server/tests/Feature/Addon/{AddonDependencyTest,AddonUpgradeTest,AddonApiTest,ArkSyncTest}.php
admin/src/app/api/addon.ts                   # 新：接口封装
admin/src/app/utils/addon.ts                 # 新：状态/提示纯函数（Vitest）
admin/src/app/views/system/addon/index.vue   # 新：插件管理页
admin/tests/addon.test.ts                    # 新
docs/superpowers/plans/2026-09-17-arkadmin-m7-wrapup.md
```

---

### Task 1: 依赖拓扑（AddonDependency + 反向保护）

**Files:** Create `server/app/Support/Addon/AddonDependency.php`、`server/tests/Feature/Addon/AddonDependencyTest.php`；Modify `AddonInstaller.php`

**Interfaces:**
- Produces（T2/T4 依赖）：
  - `AddonDependency::assertInstallable(AddonInfo $info): void`（依赖须已安装 + 无环）
  - `AddonDependency::assertEnableable(AddonInfo $info): void`（依赖须已安装且已启用 + 无环）
  - `AddonDependency::dependents(string $name, bool $enabledOnly = true): array<string>`（已安装且声明依赖 $name 的插件名）
  - `AddonInstaller::__construct(AddonManager $manager, AddonDependency $dependencies, FrameworkCache $frameworkCache)`

- [ ] **Step 1: 写失败测试** `AddonDependencyTest.php`（fixture 机制同 `AddonCommandTest`：`make_addon_dir` + `config(['arkadmin.addon_path' => storage_path('framework/addon-fixture')])`，afterEach `remove_dir` + `flushCompiled`）：

```php
<?php

use App\Support\Addon\AddonDependency;
use App\Support\Addon\AddonException;
use App\Support\Addon\AddonInstaller;
use App\Support\Addon\AddonManager;
use App\Support\Addon\Models\Addon;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    config(['arkadmin.addon_path' => storage_path('framework/addon-fixture')]);
});

afterEach(function () {
    app(AddonManager::class)->flushCompiled();
    remove_dir(storage_path('framework/addon-fixture'));
});

/** dep（被依赖者）+ user（依赖方，可选安装/启用）双 fixture */
function make_dep_pair(bool $installUser = true, bool $enableUser = true): void
{
    make_addon_dir('depa');
    make_addon_dir('userb', ['dependencies' => ['depa']]);
    $installer = app(AddonInstaller::class);
    $installer->install('depa');
    if ($installUser) {
        $installer->install('userb');
        if (! $enableUser) {
            $installer->disable('userb');
        }
    }
}

it('安装：依赖已装但未启用也能装（启用态不要求）', function () {
    make_dep_pair(installUser: false);
    app(AddonInstaller::class)->install('userb');
    expect(Addon::find('userb'))->not->toBeNull();
});

it('启用：依赖未启用被拒绝，启用依赖后可启用', function () {
    make_dep_pair(installUser: false);
    $installer = app(AddonInstaller::class);
    $installer->install('userb');
    $installer->disable('depa');
    try {
        $installer->enable('userb');
        $this->fail('应当拒绝');
    } catch (AddonException $e) {
        expect($e->getMessage())->toContain('未安装或未启用');
    }
    $installer->enable('depa');
    $installer->enable('userb');
    expect(Addon::find('userb')->enabled)->toBeTrue();
});

it('环检测：互为依赖的清单在安装时被拒绝', function () {
    // 2-环无法靠顺序安装成立（互为前提），现实暴露路径是「先装无依赖版，再改清单引入依赖」：
    make_addon_dir('ringa');    // v1：无依赖，可正常安装
    make_addon_dir('ringb', ['dependencies' => ['ringa']]);
    $installer = app(AddonInstaller::class);
    $installer->install('ringa');
    // v2：ringa 的清单改成依赖 ringb（发布后改清单的常见事故）
    $info = json_decode((string) file_get_contents(storage_path('framework/addon-fixture/ringa').'/info.json'), true);
    $info['dependencies'] = ['ringb'];
    file_put_contents(storage_path('framework/addon-fixture/ringa').'/info.json', json_encode($info));

    try {
        $installer->install('ringb');   // ringa 已安装满足依赖，但 ringa→ringb→ringa 成环
        $this->fail('应当检出环');
    } catch (AddonException $e) {
        expect($e->getMessage())->toContain('依赖成环');
    }
    expect(Addon::find('ringb'))->toBeNull();
});

it('disable/uninstall：被已启用插件依赖时拒绝，文案列出依赖方', function () {
    make_dep_pair();
    $installer = app(AddonInstaller::class);
    try {
        $installer->disable('depa');
        $this->fail('应当拒绝');
    } catch (AddonException $e) {
        expect($e->getMessage())->toContain('userb')->toContain('禁用');
    }
    try {
        $installer->uninstall('depa');
        $this->fail('应当拒绝');
    } catch (AddonException $e) {
        expect($e->getMessage())->toContain('userb')->toContain('卸载');
    }
});

it('uninstall：被禁用插件依赖同样拒绝（依赖方卸载后放行）', function () {
    make_dep_pair();
    $installer = app(AddonInstaller::class);
    $installer->disable('userb');
    try {
        $installer->uninstall('depa');
        $this->fail('应当拒绝');
    } catch (AddonException $e) {
        expect($e->getMessage())->toContain('userb');
    }
    $installer->uninstall('userb');
    $installer->disable('depa');
    $installer->uninstall('depa');
    expect(Addon::find('depa'))->toBeNull()->and(Schema::hasTable('missing'))->toBeFalse();
});

it('无依赖者时行为不变（回归）', function () {
    make_addon_dir('lonely');
    $installer = app(AddonInstaller::class);
    $installer->install('lonely');
    $installer->disable('lonely');
    $installer->uninstall('lonely');
    expect(Addon::find('lonely'))->toBeNull();
    expect(app(AddonDependency::class)->dependents('lonely'))->toBe([]);
});
```

- [ ] **Step 2: 跑测试确认失败**：`docker compose -f docker/docker-compose.yml exec -u 1000:1000 php sh -c "cd /var/www/server && php artisan test tests/Feature/Addon/AddonDependencyTest.php 2>&1 | tail -12"`（预期：类不存在 / 反向保护缺失导致 fail）
- [ ] **Step 3: 实现 `AddonDependency`**：

```php
<?php

namespace App\Support\Addon;

use App\Support\Addon\Models\Addon;

/**
 * 依赖拓扑校验（harness §4.3）：依赖图以磁盘清单（AddonManager::scan()）为准，
 * 启用态以注册表为准。dependencies 维持 string[]，不做版本约束。
 */
class AddonDependency
{
    public function __construct(protected AddonManager $manager) {}

    /** 安装校验：依赖须已安装（启用态不要求）+ 清单无环 */
    public function assertInstallable(AddonInfo $info): void
    {
        foreach ($info->dependencies as $dep) {
            if (Addon::find($dep) === null) {
                throw new AddonException("依赖插件 [{$dep}] 未安装");
            }
        }
        $this->assertAcyclic($info);
    }

    /** 启用校验：依赖须已安装且已启用 + 清单无环 */
    public function assertEnableable(AddonInfo $info): void
    {
        foreach ($info->dependencies as $dep) {
            $record = Addon::find($dep);
            if ($record === null || ! $record->enabled) {
                throw new AddonException("依赖插件 [{$dep}] 未安装或未启用，无法启用 [{$info->name}]");
            }
        }
        $this->assertAcyclic($info);
    }

    /** @return array<string> 已安装且清单声明依赖 $name 的插件（$enabledOnly=false 时含禁用态） */
    public function dependents(string $name, bool $enabledOnly = true): array
    {
        $scan = $this->manager->scan();
        $out = [];
        foreach (Addon::query()
            ->when($enabledOnly, fn ($q) => $q->where('enabled', true))
            ->get() as $record) {
            $info = $scan[$record->name] ?? null;
            if ($info !== null && in_array($name, $info->dependencies, true)) {
                $out[] = $record->name;
            }
        }

        return $out;
    }

    /** 从 $info 出发沿磁盘清单深搜，回到自身即环（清单互相引用在安装期就该炸，而不是引导期静默错乱） */
    protected function assertAcyclic(AddonInfo $info): void
    {
        $scan = $this->manager->scan();
        $stack = [[$info->name, [$info->name]]];
        while ($stack !== []) {
            [$current, $path] = array_pop($stack);
            foreach ($scan[$current]->dependencies ?? [] as $dep) {
                if ($dep === $info->name) {
                    throw new AddonException('依赖成环：'.implode(' → ', [...$path, $dep]));
                }
                if (isset($scan[$dep]) && ! in_array($dep, $path, true)) {
                    $stack[] = [$dep, [...$path, $dep]];
                }
            }
        }
    }
}
```

- [ ] **Step 4: 接入 `AddonInstaller`**（replace_in_file）：
  - 构造器：`public function __construct(protected AddonManager $manager, protected AddonDependency $dependencies, protected FrameworkCache $frameworkCache) {}`（FrameworkCache 本任务先建空壳，见 Step 5）
  - `install()`：`foreach ($info->dependencies as $dep) {...}` 段替换为 `$this->dependencies->assertInstallable($info);`
  - `enable()`：依赖检查段替换为 `$this->dependencies->assertEnableable($info);`
  - `disable()`：在 `$info = $this->mustExistOnDisk($name);` 之后加：

```php
        $deps = $this->dependencies->dependents($name);
        if ($deps !== []) {
            throw new AddonException('插件 ['.$name.'] 正被已启用插件 ['.implode('、', $deps).'] 依赖，请先禁用它们');
        }
```

  - `uninstall()`：在 enabled 检查之后、`migrate:reset` 之前加（`enabledOnly=false`：禁用态依赖方也挡，卸载顺序=先卸依赖方）：

```php
        $deps = $this->dependencies->dependents($name, false);
        if ($deps !== []) {
            throw new AddonException('插件 ['.$name.'] 正被已安装插件 ['.implode('、', $deps).'] 依赖，请先卸载它们');
        }
```

- [ ] **Step 5: 抽取 `FrameworkCache`**（T2/T3 要复用；行为与原 `refreshFrameworkCaches()` 完全一致）：

```php
<?php

namespace App\Support\Addon;

use Illuminate\Support\Facades\Artisan;

/** §11 风险对策：config/route/event 缓存在用时自动重建（装/停/卸/升级/ark:sync 共用）。失败降级为告警 */
class FrameworkCache
{
    public function rebuild(): void
    {
        $rebuild = function (string $command): void {
            try {
                Artisan::call($command);
            } catch (\Throwable $e) {
                logger()->warning("框架缓存重建失败（{$command}）：".$e->getMessage());
            }
        };
        $cachePath = base_path('bootstrap/cache');
        if (is_file($cachePath.'/config.php')) {
            $rebuild('config:cache');
        }
        if (is_file($cachePath.'/events.php')) {
            $rebuild('event:cache');
        }
        if (glob($cachePath.'/routes-*.php')) {
            $rebuild('route:cache');
        }
    }
}
```

  并把 `AddonInstaller::refreshFrameworkCaches()` 私有方法删除，全部调用点（`finish`/`disable`/`uninstall`）改为 `$this->frameworkCache->rebuild()`。
- [ ] **Step 6: 跑本任务测试 + 全量回归**（预期全绿；`AddonCommandTest` 的「依赖未安装拒绝安装」用例须仍绿）
- [ ] **Step 7: 提交** `git add server/app/Support/Addon server/tests/Feature/Addon/AddonDependencyTest.php && git commit -m "feat(m7): dependency topology with cycle detection and reverse guards"`

### Task 2: 升级机制（AddonInstaller::upgrade + addon:upgrade）

**Files:** Modify `AddonInstaller.php`、`bootstrap/app.php`；Create `Console/AddonUpgradeCommand.php`、`tests/Feature/Addon/AddonUpgradeTest.php`

**Interfaces:**
- Produces（T4 依赖）：
  - `AddonInstaller::upgrade(string $name, bool $force = false): ?AddonInfo`（null=版本未变未执行；否则返回磁盘清单；`$lastSyncedFrontend` 照旧暴露）
  - `protected function hook(AddonInfo $info, string $method, array $args = []): void`（内部签名，`upgrade` 传 `[$fromVersion]`）

- [ ] **Step 1: 写失败测试** `AddonUpgradeTest.php`（fixture + 升级日志记录钩子入参）：

```php
<?php

use App\Support\Addon\AddonException;
use App\Support\Addon\AddonInstaller;
use App\Support\Addon\AddonManager;
use App\Support\Addon\Models\Addon;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    config(['arkadmin.addon_path' => storage_path('framework/addon-fixture')]);
    (new App\Admin\Seeds\RbacSeeder)->run();
    (new App\Admin\Seeds\MenuSeeder)->run();
});

afterEach(function () {
    app(AddonManager::class)->flushCompiled();
    remove_dir(storage_path('framework/addon-fixture'));
});

const UPGRADE_LOG = 'framework/addon-fixture/upgrade.log';

/** 可升级 fixture：迁移 + 菜单/权限 + upgrade() 钩子记录 fromVersion */
function make_upgradable_addon(string $version): void
{
    $dir = make_addon_dir('upx', ['version' => $version]);
    @mkdir($dir.'/database/migrations', 0777, true);
    @mkdir($dir.'/database', 0777, true);
    $log = storage_path(UPGRADE_LOG);
    file_put_contents($dir."/database/migrations/2026_09_15_000001_create_upx_things_table.php", <<<PHP
    <?php
    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;
    return new class extends Migration {
        public function up(): void { Schema::create('upx_things', fn (Blueprint \\\$t) => \\\$t->id()); }
        public function down(): void { Schema::dropIfExists('upx_things'); }
    };
    PHP);
    file_put_contents($dir.'/database/menus.php', "<?php\nreturn [\n    ['name' => 'upx', 'title' => 'UP', 'icon' => 'Box', 'route_path' => '/upx',"
        ." 'view_path' => 'missing/index', 'permission' => 'addon.upx.index', 'sort' => 300],\n];\n");
    file_put_contents($dir.'/database/permissions.php', "<?php\nreturn ['addon.upx.export'];\n");
    make_fixture_installable($dir, 'upx');
    // 重写 Addon.php：upgrade 钩子记录 fromVersion（默认实现为空）
    file_put_contents($dir.'/src/Addon.php', <<<PHP
    <?php
    namespace Addons\\upx;
    use App\\Support\\Addon\\Contracts\\Lifecycle;
    class Addon implements Lifecycle {
        public function install(): void {}
        public function uninstall(): void {}
        public function enable(): void {}
        public function disable(): void {}
        public function upgrade(string \\\$fromVersion): void { file_put_contents('{$log}', \\\$fromVersion); }
    }
    PHP);
}

/** 升级到新版本：bump version + 追加第二张表迁移 + 追加菜单/权限 */
function upgrade_upx_fixture(): void
{
    $dir = storage_path('framework/addon-fixture/upx');
    $info = json_decode((string) file_get_contents($dir.'/info.json'), true);
    $info['version'] = '0.2.0';
    file_put_contents($dir.'/info.json', json_encode($info, JSON_UNESCAPED_UNICODE));
    file_put_contents($dir.'/database/menus.php', "<?php\nreturn [\n    ['name' => 'upx', 'title' => 'UP', 'icon' => 'Box', 'route_path' => '/upx',"
        ." 'view_path' => 'missing/index', 'permission' => 'addon.upx.index', 'sort' => 300],\n"
        ."    ['name' => 'upx.extra', 'title' => 'UP2', 'icon' => 'Box', 'route_path' => '/upx/extra',"
        ." 'view_path' => 'missing/index', 'permission' => 'addon.upx.extra', 'sort' => 301],\n];\n");
    file_put_contents($dir."/database/migrations/2026_09_16_000001_create_upx_extra_table.php", <<<PHP
    <?php
    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;
    return new class extends Migration {
        public function up(): void { Schema::create('upx_extra', fn (Blueprint \\\$t) => \\\$t->id()); }
        public function down(): void { Schema::dropIfExists('upx_extra'); }
    };
    PHP);
}

it('升级：新增迁移执行、钩子收到旧版本、菜单/权限补齐、注册表写回', function () {
    make_upgradable_addon('0.1.0');
    $installer = app(AddonInstaller::class);
    $installer->install('upx');
    expect(Schema::hasTable('upx_things'))->toBeTrue()
        ->and(Schema::hasTable('upx_extra'))->toBeFalse()
        ->and(Addon::find('upx')->version)->toBe('0.1.0');

    upgrade_upx_fixture();
    $info = $installer->upgrade('upx');

    expect($info?->version)->toBe('0.2.0')
        ->and(Schema::hasTable('upx_extra'))->toBeTrue()
        ->and(Addon::find('upx')->version)->toBe('0.2.0')
        ->and(Addon::find('upx')->enabled)->toBeTrue()
        ->and(file_get_contents(storage_path(UPGRADE_LOG)))->toBe('0.1.0')
        ->and(\Spatie\Permission\Models\Permission::where('name', 'addon.upx.extra')->exists())->toBeTrue()
        ->and(\App\Admin\Models\Menu::where('name', 'upx.extra')->exists())->toBeTrue();
});

it('版本未变不执行；--force 无条件补跑', function () {
    make_upgradable_addon('0.1.0');
    $installer = app(AddonInstaller::class);
    $installer->install('upx');
    // 忘 bump version 只改了迁移文件（迁移盲区典型现场）
    file_put_contents(storage_path('framework/addon-fixture/upx')."/database/migrations/2026_09_16_000001_create_upx_extra_table.php", <<<PHP
    <?php
    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;
    return new class extends Migration {
        public function up(): void { Schema::create('upx_extra', fn (Blueprint \\\$t) => \\\$t->id()); }
        public function down(): void { Schema::dropIfExists('upx_extra'); }
    };
    PHP);

    expect($installer->upgrade('upx'))->toBeNull()
        ->and(Schema::hasTable('upx_extra'))->toBeFalse();

    $installer->upgrade('upx', force: true);
    expect(Schema::hasTable('upx_extra'))->toBeTrue()
        ->and(Addon::find('upx')->version)->toBe('0.1.0');   // --force 不改版本，只补内容
});

it('未安装 / 框架版本不满足时报错', function () {
    make_upgradable_addon('0.1.0');
    $installer = app(AddonInstaller::class);
    try {
        $installer->upgrade('upx');
        $this->fail('应当拒绝');
    } catch (AddonException $e) {
        expect($e->getMessage())->toContain('未安装');
    }
    $installer->install('upx');
    $info = json_decode((string) file_get_contents(storage_path('framework/addon-fixture/upx').'/info.json'), true);
    $info['support_version'] = '99.0.0';
    file_put_contents(storage_path('framework/addon-fixture/upx').'/info.json', json_encode($info));
    try {
        $installer->upgrade('upx');
        $this->fail('应当拒绝');
    } catch (AddonException $e) {
        expect($e->getMessage())->toContain('框架版本');
    }
});

it('addon:upgrade 命令：单插件与 --all 扫描', function () {
    make_upgradable_addon('0.1.0');
    app(AddonInstaller::class)->install('upx');
    upgrade_upx_fixture();

    $this->artisan('addon:upgrade', ['name' => 'upx'])->assertExitCode(0);
    expect(Addon::find('upx')->version)->toBe('0.2.0');

    upgrade_upx_fixture_revert();   // 见下方 helper：把 info.json 改回 0.1.0 再 bump 到 0.3.0
    $this->artisan('addon:upgrade', ['--all'])->assertExitCode(0);
    expect(Addon::find('upx')->version)->toBe('0.3.0');
});

function upgrade_upx_fixture_revert(): void
{
    $dir = storage_path('framework/addon-fixture/upx');
    $info = json_decode((string) file_get_contents($dir.'/info.json'), true);
    $info['version'] = '0.3.0';
    file_put_contents($dir.'/info.json', json_encode($info, JSON_UNESCAPED_UNICODE));
}
```

- [ ] **Step 2: 跑测试确认失败**（upgrade 方法不存在）
- [ ] **Step 3: 实现**：
  - `AddonInstaller::upgrade()`：

```php
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
        $this->finish($info);
        $this->syncFrontend($info);

        return $info;
    }
```

  - `hook()` 支持 args：`$addon->{$method}(...$args);`
  - `enable()` 在 `mustExistOnDisk` 后补 `isSupported` 复核（消息文案与 install 相同）
  - `Console/AddonUpgradeCommand.php`：

```php
<?php

namespace App\Support\Addon\Console;

use App\Support\Addon\AddonException;
use App\Support\Addon\AddonInstaller;
use App\Support\Addon\AddonManager;
use App\Support\Addon\Models\Addon;
use Illuminate\Console\Command;

class AddonUpgradeCommand extends Command
{
    protected $signature = 'addon:upgrade {name? : 插件名；省略时等价 --all} {--all : 扫描全部已安装插件} {--force : 跳过版本比较（版本未 bump 但需补跑迁移）}';

    protected $description = '升级插件：执行新增迁移 → upgrade 钩子 → 补菜单/权限 → 写回版本';

    public function handle(AddonInstaller $installer, AddonManager $manager): int
    {
        $name = $this->argument('name');
        $targets = ($name !== null && ! $this->option('all'))
            ? [(string) $name]
            : collect($manager->scan())->keys()->intersect(
                Addon::query()->pluck('name')->all()
            )->values()->all();

        $upgraded = 0;
        foreach ($targets as $target) {
            try {
                $info = $installer->upgrade($target, (bool) $this->option('force'));
            } catch (AddonException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
            if ($info === null) {
                $this->line("插件 [{$target}] 已是最新（如需补跑迁移请加 --force）");
                continue;
            }
            $upgraded++;
            $this->info("插件 [{$target}] 已升级 {$info->version}");
            if ($installer->lastSyncedFrontend !== null) {
                $this->line('生产环境请执行 cd admin && npm run build 完成后台构建');
            }
        }
        if ($upgraded === 0) {
            $this->line('没有需要升级的插件');
        }

        return self::SUCCESS;
    }
}
```

  - `bootstrap/app.php`：`withCommands` 数组在 `AddonClearCommand::class` 后加 `AddonUpgradeCommand::class`（use 行同步）。
- [ ] **Step 4: 跑本任务测试 + 全量回归**
- [ ] **Step 5: 提交** `git commit -m "feat(m7): addon upgrade flow with force flag and hook invocation"`

### Task 3: ark:sync 命令

**Files:** Create `server/app/Support/Setup/FrameworkSync.php`、`server/app/Support/Addon/Console/ArkSyncCommand.php`、`server/tests/Feature/Addon/ArkSyncTest.php`；Modify `DatabaseSeeder.php`、`bootstrap/app.php`

**Interfaces:**
- Produces（T4 文案引用）：
  - `App\Support\Setup\FrameworkSync::run(bool $withSystemAddons = true): array{installed: list<string>, skipped: array<string,string>}`

- [ ] **Step 1: 写失败测试** `ArkSyncTest.php`：

```php
<?php

use App\Admin\Models\Admin;
use App\Support\Addon\Models\Addon;
use App\Support\Setup\FrameworkSync;

beforeEach(function () {
    (new App\Admin\Seeds\RbacSeeder)->run();
    (new App\Admin\Seeds\MenuSeeder)->run();
});

afterEach(function () {
    app(App\Support\Addon\AddonManager::class)->flushCompiled();
});

it('权限被回收后 ark:sync 恢复超管全部权限（M6 踩坑回归）', function () {
    super_admin()->revokePermissionTo('system.setting.update');
    expect(super_admin()->hasPermissionTo('system.setting.update'))->toBeFalse();

    $this->artisan('ark:sync')->assertExitCode(0);
    expect(super_admin()->hasPermissionTo('system.setting.update'))->toBeTrue()
        ->and(super_admin()->hasPermissionTo('system.admin.index'))->toBeTrue();
});

it('系统插件缺失时幂等跳过、已装时跳过、未装时安装', function () {
    config(['arkadmin.system_addons' => ['settings', 'ghost_addon']]);
    $this->artisan('ark:sync')->assertExitCode(0);
    expect(Addon::find('settings'))->not->toBeNull();

    $result = app(FrameworkSync::class)->run();
    expect($result['installed'])->toBe([])
        ->and($result['skipped'])->toHaveKey('settings')
        ->and($result['skipped'])->toHaveKey('ghost_addon');
});

it('--no-addons 只同步权限与菜单', function () {
    config(['arkadmin.system_addons' => ['settings']]);
    $this->artisan('ark:sync', ['--no-addons'])->assertExitCode(0);
    expect(Addon::find('settings'))->toBeNull()
        ->and(Admin::where('username', 'admin')->exists())->toBeTrue();
});

it('db:seed 与 FrameworkSync 行为一致（委托改造回归）', function () {
    config(['arkadmin.system_addons' => ['settings', 'op_logs']]);
    $this->artisan('db:seed', ['--class' => 'DatabaseSeeder', '--force' => true])->assertExitCode(0);
    expect(Addon::find('settings'))->not->toBeNull()
        ->and(Addon::find('op_logs'))->not->toBeNull();
});
```

- [ ] **Step 2: 确认失败**（FrameworkSync 不存在）
- [ ] **Step 3: 实现 `FrameworkSync`**（`server/app/Support/Setup/FrameworkSync.php`）：

```php
<?php

namespace App\Support\Setup;

use App\Admin\Seeds\MenuSeeder;
use App\Admin\Seeds\RbacSeeder;
use App\Support\Addon\AddonException;
use App\Support\Addon\AddonInstaller;

/**
 * 把代码里声明的框架内容同步到运行环境（AGENTS「升级/新增框架权限后」）：
 * 权限 + 菜单 + 捆绑系统插件，全幂等，可重复执行。db:seed 与 ark:sync 共用。
 */
class FrameworkSync
{
    public function __construct(protected AddonInstaller $installer) {}

    /** @return array{installed: list<string>, skipped: array<string,string>} */
    public function run(bool $withSystemAddons = true): array
    {
        (new RbacSeeder)->run();
        (new MenuSeeder)->run();

        $installed = [];
        $skipped = [];
        if ($withSystemAddons) {
            foreach ((array) config('arkadmin.system_addons', ['settings', 'op_logs']) as $addon) {
                $name = (string) $addon;
                try {
                    $this->installer->install($name);
                    $installed[] = $name;
                } catch (AddonException $e) {
                    $skipped[$name] = $e->getMessage();
                }
            }
        }

        return ['installed' => $installed, 'skipped' => $skipped];
    }
}
```

- [ ] **Step 4: `ArkSyncCommand` + 注册**：

```php
<?php

namespace App\Support\Addon\Console;

use App\Support\Addon\FrameworkCache;
use App\Support\Setup\FrameworkSync;
use Illuminate\Console\Command;

class ArkSyncCommand extends Command
{
    protected $signature = 'ark:sync {--no-addons : 只同步框架权限与菜单，不安装系统插件}';

    protected $description = '同步框架声明到运行环境：权限 + 菜单 + 捆绑系统插件（幂等，升级框架/插件后执行）';

    public function handle(FrameworkSync $sync, FrameworkCache $cache): int
    {
        $result = $sync->run(! (bool) $this->option('no-addons'));
        $this->info('框架权限与菜单已同步');
        foreach ($result['installed'] as $addon) {
            $this->info("系统插件 [{$addon}] 已安装");
        }
        foreach ($result['skipped'] as $addon => $reason) {
            $this->line("系统插件 [{$addon}] 跳过：{$reason}");
        }
        $cache->rebuild();
        $this->line('若页面按钮/菜单未更新，请刷新浏览器（前端按 /auth/me 权限串渲染）');

        return self::SUCCESS;
    }
}
```

  `bootstrap/app.php` 的 `withCommands` 追加 `ArkSyncCommand::class`（use `App\Support\Addon\Console\ArkSyncCommand`）。
- [ ] **Step 5: `DatabaseSeeder` 改造（委托，消重）**：

```php
    public function run(): void
    {
        $result = app(\App\Support\Setup\FrameworkSync::class)->run();
        foreach ($result['installed'] as $addon) {
            $this->command?->info("系统插件 [{$addon}] 已安装");
        }
        foreach ($result['skipped'] as $addon => $reason) {
            $this->command?->line("系统插件 [{$addon}] 跳过：{$reason}");
        }
    }
```

- [ ] **Step 6: 跑本任务测试 + 全量回归**（`Harness/SystemPluginsTest` 依赖 db:seed 行为，必须仍绿）
- [ ] **Step 7: 提交** `git commit -m "feat(m7): ark:sync command unifying permission menu and system addon setup"`

### Task 4: 插件管理 HTTP API

**Files:** Create `server/app/Admin/Http/Controllers/AddonController.php`、`tests/Feature/Addon/AddonApiTest.php`；Modify `routes/api.php`、`RbacSeeder.php`、`MenuSeeder.php`

**Interfaces:**
- Consumes：`AddonInstaller::{install,enable,disable,uninstall,upgrade}`、`AddonDependency::dependents`、`AddonManager::scan`、`AddonInstaller::$lastSyncedFrontend`
- Produces（T5 依赖）——端点与行结构：
  - `GET /api/admin/addons`（`system.addon.index`）→ `data: AddonRow[]`
  - `POST /api/admin/addons {name}`（store）→ `data: {needs_build: bool}`
  - `PUT /api/admin/addons/{name} {action: enable|disable|upgrade, force?}`（update）→ `data: {upgraded: bool, needs_build: bool}`（action=upgrade 且无新版时 `upgraded:false`）
  - `DELETE /api/admin/addons/{name}?keep_data=1`（destroy）→ `data: null`
  - `AddonRow = {name, title, description, version(磁盘), installed_version, installed, enabled, upgradable, system, dependencies, missing_dependencies, dependents, install_time|null, disk_missing?}`

- [ ] **Step 1: 权限与菜单**（`RbacSeeder` matrix 增 `'addon'`；`MenuSeeder` system 子菜单数组内追加，sort 排菜单管理之后）：

```php
        ['name' => 'system.addon', 'title' => '插件管理', 'icon' => 'Grid',
            'route_path' => '/system/addon', 'view_path' => 'system/addon/index',
            'permission' => 'system.addon.index', 'sort' => 4],
```

- [ ] **Step 2: 写失败测试** `AddonApiTest.php`（403 用例遵守「无权限账号发起本测试内首个带认证请求」约定，注释说明）：

```php
<?php

use App\Admin\Models\Admin;
use App\Support\Addon\AddonManager;
use App\Support\Addon\Models\Addon;

beforeEach(function () {
    (new App\Admin\Seeds\RbacSeeder)->run();
    (new App\Admin\Seeds\MenuSeeder)->run();
});

afterEach(function () {
    app(AddonManager::class)->flushCompiled();
});

it('列表：磁盘插件全量（含未安装）+ 注册表状态 + 可升级标记', function () {
    config(['arkadmin.addon_path' => storage_path('framework/addon-fixture')]);
    make_addon_dir('lista', ['version' => '0.2.0']);
    app(App\Support\Addon\AddonInstaller::class)->install('lista');
    // 注册表还停在 0.1.0（install 后磁盘 bump 版本模拟「待升级」）
    $info = json_decode((string) file_get_contents(storage_path('framework/addon-fixture/lista').'/info.json'), true);
    $info['version'] = '0.3.0';
    file_put_contents(storage_path('framework/addon-fixture/lista').'/info.json', json_encode($info));
    make_addon_dir('listb');    // 未安装

    $rows = $this->getJson('/api/admin/addons', ['Authorization' => 'Bearer '.admin_token()])
        ->assertOk()->assertJsonPath('code', 0)->json('data');
    $a = collect($rows)->firstWhere('name', 'lista');
    $b = collect($rows)->firstWhere('name', 'listb');
    expect($a['installed'])->toBeTrue()->and($a['enabled'])->toBeTrue()
        ->and($a['upgradable'])->toBeTrue()
        ->and($a['installed_version'])->toBe('0.2.0')->and($a['version'])->toBe('0.3.0')
        ->and($b['installed'])->toBeFalse()->and($b['upgradable'])->toBeFalse();
});

it('安装/升级/卸载走通，带前端插件返回 needs_build', function () {
    $token = admin_token();
    $this->postJson('/api/admin/addons', ['name' => 'demo'], ['Authorization' => "Bearer {$token}"])
        ->assertOk()->assertJsonPath('code', 0)->assertJsonPath('data.needs_build', true);

    $this->putJson('/api/admin/addons/demo', ['action' => 'disable'], ['Authorization' => "Bearer {$token}"])
        ->assertOk()->assertJsonPath('code', 0);
    $this->putJson('/api/admin/addons/demo', ['action' => 'enable'], ['Authorization' => "Bearer {$token}"])
        ->assertOk();
    // 版本相同：upgraded=false
    $this->putJson('/api/admin/addons/demo', ['action' => 'upgrade'], ['Authorization' => "Bearer {$token}"])
        ->assertOk()->assertJsonPath('data.upgraded', false);
    // 未安装插件升级
    $this->putJson('/api/admin/addons/cms', ['action' => 'upgrade'], ['Authorization' => "Bearer {$token}"])
        ->assertOk()->assertJsonPath('code', 1);

    $this->deleteJson('/api/admin/addons/demo?keep_data=1', [], ['Authorization' => "Bearer {$token}"])
        ->assertOk()->assertJsonPath('code', 0);
    expect(Addon::find('demo'))->toBeNull();
});

it('系统插件保护：disable/uninstall 在 HTTP 层被拒，enable/install 不受限', function () {
    $token = admin_token();
    app(App\Support\Addon\AddonInstaller::class)->install('settings');

    $this->putJson('/api/admin/addons/settings', ['action' => 'disable'], ['Authorization' => "Bearer {$token}"])
        ->assertOk()->assertJsonPath('code', 1)->assertJsonPath('msg', fn ($m) => str_contains($m, '系统插件'));
    $resp = $this->deleteJson('/api/admin/addons/settings', [], ['Authorization' => "Bearer {$token}"]);
    $resp->assertOk()->assertJsonPath('code', 1);
    expect($resp->json('msg'))->toContain('系统插件')
        ->and(Addon::find('settings')->enabled)->toBeTrue();

    // 禁用/卸载其它插件不受影响
    app(App\Support\Addon\AddonInstaller::class)->install('demo');
    $this->putJson('/api/admin/addons/demo', ['action' => 'disable'], ['Authorization' => "Bearer {$token}"])
        ->assertOk()->assertJsonPath('code', 0);
});

it('参数校验：未知 action 422、未知插件安装 code 1', function () {
    $token = admin_token();
    $this->putJson('/api/admin/addons/demo', ['action' => 'explode'], ['Authorization' => "Bearer {$token}"])
        ->assertStatus(422);
    $this->postJson('/api/admin/addons', ['name' => 'ghost'], ['Authorization' => "Bearer {$token}"])
        ->assertOk()->assertJsonPath('code', 1);
});

// 403 用例必须让无权限账号发起本测试内首个带认证请求（Sanctum guard 测试进程缓存首个解析用户）
it('无 system.addon.* 权限返回 403 信封', function () {
    Admin::create(['username' => 'plain', 'password' => 'x123456', 'status' => 1]);
    $t2 = $this->postJson('/api/admin/auth/login', ['username' => 'plain', 'password' => 'x123456'])->json('data.token');

    $this->getJson('/api/admin/addons', ['Authorization' => "Bearer {$t2}"])->assertOk()->assertJsonPath('code', 403);
    $this->postJson('/api/admin/addons', ['name' => 'demo'], ['Authorization' => "Bearer {$t2}"])->assertOk()->assertJsonPath('code', 403);
});
```

- [ ] **Step 3: 确认失败**（路由 404 / 权限缺失）
- [ ] **Step 4: 实现 `AddonController`**：

```php
<?php

namespace App\Admin\Http\Controllers;

use App\Support\Addon\AddonDependency;
use App\Support\Addon\AddonException;
use App\Support\Addon\AddonInfo;
use App\Support\Addon\AddonInstaller;
use App\Support\Addon\AddonManager;
use App\Support\Addon\Models\Addon;
use App\Support\Http\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class AddonController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected AddonManager $manager,
        protected AddonInstaller $installer,
        protected AddonDependency $dependencies,
    ) {}

    /** 磁盘扫描 ∪ 注册表：管理界面的唯一列表来源（未安装插件也可见） */
    public function index(): JsonResponse
    {
        $scan = $this->manager->scan();
        $records = Addon::all()->keyBy('name');

        $rows = [];
        foreach ($scan as $info) {
            $rows[] = $this->row($info, $records->get($info->name));
        }
        // 注册表有、磁盘缺失（异常态）：只读展示，提示排查
        foreach ($records->reject(fn ($r, $name) => isset($scan[$name])) as $record) {
            $rows[] = [
                'name' => $record->name, 'title' => $record->title, 'description' => '',
                'version' => '', 'installed_version' => $record->version,
                'installed' => true, 'enabled' => (bool) $record->enabled,
                'upgradable' => false, 'system' => false,
                'dependencies' => [], 'missing_dependencies' => [], 'dependents' => [],
                'install_time' => $record->install_time?->toDateTimeString(),
                'disk_missing' => true,
            ];
        }

        return $this->success($rows);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate(['name' => 'required|string|max:64']);
        try {
            $this->installer->install((string) $request->input('name'));
        } catch (AddonException $e) {
            return $this->fail(1, $e->getMessage());
        }

        return $this->success(
            ['needs_build' => $this->installer->lastSyncedFrontend !== null],
            '安装成功'
        );
    }

    /** action 统一入口：enable/disable/upgrade（权限串 system.addon.update） */
    public function update(Request $request, string $addon): JsonResponse
    {
        $request->validate([
            'action' => 'required|in:enable,disable,upgrade',
            'force' => 'nullable|boolean',
        ]);
        $action = (string) $request->input('action');
        $systemAddons = (array) config('arkadmin.system_addons', []);
        // 前端禁按钮只是提示，后端必须拦：绕过界面打接口的代价是设置页/日志功能损坏
        if (in_array($action, ['disable'], true) && in_array($addon, $systemAddons, true)) {
            return $this->fail(1, "系统插件 [{$addon}] 不能停用，请使用 CLI 操作");
        }
        if ($action === 'upgrade') {
            try {
                $info = $this->installer->upgrade($addon, (bool) $request->boolean('force'));
            } catch (AddonException $e) {
                return $this->fail(1, $e->getMessage());
            }

            return $this->success([
                'upgraded' => $info !== null,
                'needs_build' => $this->installer->lastSyncedFrontend !== null,
            ], $info === null ? '已是最新版本' : "已升级到 {$info->version}");
        }
        try {
            $this->installer->{$action}($addon);
        } catch (AddonException $e) {
            return $this->fail(1, $e->getMessage());
        }

        return $this->success(null, $action === 'enable' ? '已启用' : '已禁用');
    }

    public function destroy(Request $request, string $addon): JsonResponse
    {
        if (in_array($addon, (array) config('arkadmin.system_addons', []), true)) {
            return $this->fail(1, "系统插件 [{$addon}] 不能卸载，请使用 CLI 操作");
        }
        try {
            $this->installer->uninstall($addon, $request->boolean('keep_data'));
        } catch (AddonException $e) {
            return $this->fail(1, $e->getMessage());
        }

        return $this->success(null, '卸载成功');
    }

    protected function row(AddonInfo $info, ?Addon $record): array
    {
        $missing = array_values(array_filter(
            $info->dependencies,
            fn ($dep) => Addon::find($dep) === null
        ));

        return [
            'name' => $info->name,
            'title' => $info->title,
            'description' => $info->description,
            'version' => $info->version,
            'installed_version' => $record?->version,
            'installed' => $record !== null,
            'enabled' => (bool) $record?->enabled,
            'upgradable' => $record !== null
                && version_compare($info->version, (string) $record->version, '>'),
            'system' => in_array($info->name, (array) config('arkadmin.system_addons', []), true),
            'dependencies' => $info->dependencies,
            'missing_dependencies' => $missing,
            'dependents' => $this->dependencies->dependents($info->name, false),
            'install_time' => $record?->install_time?->toDateTimeString(),
        ];
    }
}
```

  `routes/api.php` auth 组内追加：

```php
        // M7 插件管理（§4.1）
        Route::get('addons', [AddonController::class, 'index'])->middleware('permission:system.addon.index');
        Route::post('addons', [AddonController::class, 'store'])->middleware('permission:system.addon.store');
        Route::put('addons/{addon}', [AddonController::class, 'update'])->middleware('permission:system.addon.update');
        Route::delete('addons/{addon}', [AddonController::class, 'destroy'])->middleware('permission:system.addon.destroy');
```

  use 行加 `App\Admin\Http\Controllers\AddonController`。注意：路由参数 `{addon}` 不与插件动态路由 `/api/admin/addon/<key>`（单数）冲突。
- [ ] **Step 5: 跑本任务测试 + 全量回归**（`AddonLifecycleTest` 等既有用例须仍绿；种子新增权限会改变超管权限数，若有测试硬断言权限总数需同步）
- [ ] **Step 6: 提交** `git commit -m "feat(m7): addon management http api with system addon protection"`

### Task 5: 插件管理前端页

**Files:** Create `admin/src/app/api/addon.ts`、`admin/src/app/utils/addon.ts`、`admin/src/app/views/system/addon/index.vue`、`admin/tests/addon.test.ts`

**Interfaces:** Consumes T4 的四个端点与 `AddonRow` 结构。

- [ ] **Step 1: 写失败测试** `admin/tests/addon.test.ts`：

```ts
import { describe, expect, it } from 'vitest'
import { addonStatus, buildNotice, type AddonRow } from '../src/app/utils/addon'

function row(overrides: Partial<AddonRow>): AddonRow {
  return {
    name: 'demo', title: '演示', description: '', version: '0.1.0', installed_version: '0.1.0',
    installed: true, enabled: false, upgradable: false, system: false,
    dependencies: [], missing_dependencies: [], dependents: [], install_time: null,
    ...overrides,
  }
}

describe('addonStatus', () => {
  it('未安装/缺依赖/已停用/已启用/可升级 按序判定', () => {
    expect(addonStatus(row({ installed: false }))).toEqual({ text: '未安装', type: 'info' })
    expect(addonStatus(row({ installed: false, missing_dependencies: ['dep1'] })))
      .toEqual({ text: '缺少依赖 dep1', type: 'danger' })
    expect(addonStatus(row({}))).toEqual({ text: '已禁用', type: 'warning' })
    expect(addonStatus(row({ enabled: true }))).toEqual({ text: '已启用', type: 'success' })
    expect(addonStatus(row({ enabled: true, upgradable: true })))
      .toEqual({ text: '可升级', type: 'primary' })
  })
})

describe('buildNotice', () => {
  it('needs_build 时给出持久提示文案', () => {
    expect(buildNotice({ needs_build: true })).toContain('npm run build')
    expect(buildNotice({ needs_build: false })).toBe('')
  })
})
```

- [ ] **Step 2: 实现** `admin/src/app/utils/addon.ts`：

```ts
export interface AddonRow {
  name: string
  title: string
  description: string
  version: string
  installed_version: string | null
  installed: boolean
  enabled: boolean
  upgradable: boolean
  system: boolean
  dependencies: string[]
  missing_dependencies: string[]
  dependents: string[]
  install_time: string | null
  disk_missing?: boolean
}

export interface AddonStatus {
  text: string
  type: 'success' | 'warning' | 'info' | 'danger' | 'primary'
}

/** 行状态标签：缺依赖 > 未安装 > 可升级 > 已启用 > 已禁用（判定顺序即展示优先级） */
export function addonStatus(row: AddonRow): AddonStatus {
  if (row.disk_missing) return { text: '磁盘缺失', type: 'danger' }
  if (row.missing_dependencies.length > 0) {
    return { text: `缺少依赖 ${row.missing_dependencies.join('、')}`, type: 'danger' }
  }
  if (!row.installed) return { text: '未安装', type: 'info' }
  if (row.upgradable) return { text: '可升级', type: 'primary' }
  return row.enabled ? { text: '已启用', type: 'success' } : { text: '已禁用', type: 'warning' }
}

/** 写操作响应 → 顶部持久提示（插件前端同步后需重新构建才能生效于生产 dist） */
export function buildNotice(resp: { needs_build?: boolean }): string {
  return resp.needs_build ? '插件前端已同步，请执行 cd admin && npm run build 重新构建后台' : ''
}
```

- [ ] **Step 3: 实现 `admin/src/app/api/addon.ts`**：

```ts
import request from './request'
import type { AddonRow } from '../utils/addon'

export type AddonAction = 'enable' | 'disable' | 'upgrade'

export const addonApi = {
  list: () => request.get<never, AddonRow[]>('/addons'),
  install: (name: string) => request.post<never, { needs_build: boolean }>('/addons', { name }),
  update: (name: string, action: AddonAction, force = false) =>
    request.put<never, { upgraded: boolean; needs_build: boolean }>(`/addons/${name}`, { action, force }),
  uninstall: (name: string, keepData = false) =>
    request.delete<never, unknown>(`/addons/${name}`, { params: keepData ? { keep_data: 1 } : {} }),
}
```

- [ ] **Step 4: 实现 `admin/src/app/views/system/addon/index.vue`**（模板风格对齐 `system/menu/index.vue`：领域文案内联、通用动作用 `t('common.*')`）：

```vue
<template>
  <el-card>
    <template #header>
      <div class="header">
        <span>插件管理</span>
        <el-button link @click="load">刷新</el-button>
      </div>
    </template>

    <el-alert v-if="notice" :title="notice" type="warning" :closable="false" show-icon style="margin-bottom: 12px" />

    <el-table :data="rows" border>
      <el-table-column prop="name" label="标识" min-width="110" />
      <el-table-column prop="title" label="名称" min-width="120" />
      <el-table-column label="版本" min-width="120">
        <template #default="{ row }">
          <span>{{ row.installed ? row.installed_version : '—' }}</span>
          <el-tag v-if="row.upgradable" type="primary" size="small" style="margin-left: 6px">→ {{ row.version }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="依赖" min-width="120">
        <template #default="{ row }">
          <span>{{ row.dependencies.length ? row.dependencies.join('、') : '—' }}</span>
        </template>
      </el-table-column>
      <el-table-column label="状态" min-width="130">
        <template #default="{ row }">
          <el-tag :type="addonStatus(row).type">{{ addonStatus(row).text }}</el-tag>
          <el-tag v-if="row.system" type="info" size="small" style="margin-left: 6px">系统</el-tag>
        </template>
      </el-table-column>
      <el-table-column prop="install_time" label="安装时间" min-width="150" />
      <el-table-column label="操作" width="230">
        <template #default="{ row }">
          <el-button v-permission="'system.addon.store'" v-if="!row.installed && !row.disk_missing"
            link type="success" @click="install(row)">安装</el-button>
          <template v-if="row.installed && !row.disk_missing">
            <el-button v-permission="'system.addon.update'" v-if="row.upgradable"
              link type="primary" @click="upgrade(row)">升级</el-button>
            <el-button v-permission="'system.addon.update'" v-if="row.enabled && !row.system"
              link type="warning" @click="toggle(row, 'disable')">禁用</el-button>
            <el-button v-permission="'system.addon.update'" v-if="!row.enabled"
              link type="success" @click="toggle(row, 'enable')">启用</el-button>
            <el-button v-permission="'system.addon.destroy'" v-if="!row.enabled && !row.system"
              link type="danger" @click="remove(row)">卸载</el-button>
          </template>
        </template>
      </el-table-column>
    </el-table>
  </el-card>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ElMessage, ElMessageBox } from 'element-plus'
import { addonApi, type AddonAction } from '../../api/addon'
import { addonStatus, buildNotice, type AddonRow } from '../../utils/addon'

const { t } = useI18n()
const rows = ref<AddonRow[]>([])
const notice = ref('')

async function load() {
  rows.value = await addonApi.list()
}

async function run(fn: () => Promise<{ needs_build?: boolean }>, okMsg = '') {
  try {
    const resp = await fn()
    notice.value = buildNotice(resp)
    if (okMsg) ElMessage.success(okMsg)
    await load()
  } catch {
    // 拦截器已提示
  }
}

function install(row: AddonRow) {
  return run(() => addonApi.install(row.name), '安装成功')
}

function toggle(row: AddonRow, action: AddonAction) {
  return run(() => addonApi.update(row.name, action), action === 'enable' ? '已启用' : '已禁用')
}

function upgrade(row: AddonRow) {
  return run(() => addonApi.update(row.name, 'upgrade'), '升级成功')
}

async function remove(row: AddonRow) {
  const keepData = await confirmUninstall(row)
  if (keepData === null) return
  return run(() => addonApi.uninstall(row.name, keepData), '卸载成功')
}

/** 卸载确认：三选（取消 / 保留数据 / 全部回滚），null=取消 */
async function confirmUninstall(row: AddonRow): Promise<boolean | null> {
  const deps = row.dependents.length > 0 ? `（正被 ${row.dependents.join('、')} 依赖）` : ''
  try {
    await ElMessageBox.confirm(
      `卸载将回滚插件业务表${deps}，确定卸载「${row.title}」？`,
      t('common.tip'),
      { type: 'warning', distinguishCancelAndClose: true,
        cancelButtonText: '保留数据', confirmButtonText: '全部回滚' },
    )
    return false
  } catch (action) {
    if (action === 'cancel') return true    // 保留数据
    return null                             // close = 放弃
  }
}

onMounted(load)
</script>

<style scoped>
.header { display: flex; align-items: center; justify-content: space-between; }
</style>
```

- [ ] **Step 5: 验证**：`cd admin && npm test`（新增用例转绿）+ `npx vue-tsc -b` 通过
- [ ] **Step 6: 提交** `git commit -m "feat(m7): admin addon management page with upgrade and uninstall flows"`

### Task 6: 验收收尾

**Files:** Modify `AGENTS.md`、`docs/superpowers/specs/2026-09-17-arkadmin-harness.md`（§5 路线图标状态）；Create `docs/superpowers/plans/2026-09-17-arkadmin-m7-wrapup.md`

- [ ] **Step 1: 开发库实装验证**（dev 库）：
  - `php artisan ark:sync`（幂等，验证三合一）；
  - 模拟升级：临时把 `addons/cms/info.json` version 提到 0.2.0 + 手工验证 `addon:upgrade cms --force`（补迁移入口）→ 版本写回；再还原（或直接保留 0.1.0 后用 curl 走 HTTP upgrade 返回 upgraded:false）；
  - curl 全走查：`GET /api/admin/addons` → `POST`（装 demo）→ `PUT disable/enable` → `DELETE`；系统插件 `PUT disable` 得 code 1；
  - 前端 `npm run dev` 起后登录目检「插件管理」页（菜单就位、状态标签、操作按钮随权限显隐）。
- [ ] **Step 2: AGENTS.md 更新**：
  - 「升级/新增框架权限后」条目改为推荐 `php artisan ark:sync`（等价 db:seed 且含缓存重建）；
  - 插件 CLI 清单追加 `addon:upgrade {name} [--all] [--force]` 与 `ark:sync [--no-addons]`；
  - 新增一小节「插件升级」：新增迁移必须经 `addon:upgrade` 执行（`--force` 补漏 bump），升级后需 `npm run build`。
- [ ] **Step 3: harness 规格**：§5 路线图 M7 行标注「完成（见 wrapup）」；§4 尾部加一行「计划增量：迁移盲区修复（`addon:upgrade --force`）与 `ark:sync` 升级命令，见 `2026-09-17-arkadmin-m7-wrapup.md`」。
- [ ] **Step 4: 全量回归**：后端 `php artisan test` 全绿、`npm test` 全绿、`vue-tsc -b` 通过、`npm run build` 成功（dist 含插件管理 chunk）。
- [ ] **Step 5: 提交** `git commit -m "docs(m7): acceptance records and workspace guide updates"`
- [ ] **Step 6: 评审轮**（M2–M6 惯例）：dispatch code-explorer 新视角评审 `3ba3615..HEAD` 对应本里程碑提交 → 逐条核实 → `fix(m7): 按评审修复…` → `docs(m7): 评审轮记录…`，记录写进 wrapup「评审轮记录」小节。
- [ ] **Step 7: wrapup**（含「GUI 走查清单（待用户确认）」小节：① 插件管理页列表含已装/未装插件且状态正确 ② 安装 demo → 顶部出现构建提示条 ③ 禁用 demo 后其菜单消失、启用恢复 ④ 系统插件无禁用/卸载按钮 ⑤ 给无权限账号登录看不到插件管理菜单）。

## 范围外

zip 打包导入导出；依赖版本约束（`string[]` 维持）；素材库插件化（backlog）；boot 期自动升级；部署文档（搁置，升级流程先进 AGENTS.md，部署里程碑再抽独立文档）；M8 正确性打磨（另起会话）。
