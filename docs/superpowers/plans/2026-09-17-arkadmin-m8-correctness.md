# ArkAdmin M8 正确性打磨 — 实施计划

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:executing-plans。Steps 用 checkbox 跟踪。

**Goal:** 插件机制全链正确性收口：生命周期并发互斥（Cache 原子锁）、FrameworkCache 路径注入直测、info.json 版本格式校验三个已定案修复项 + 全链 fresh-eyes 审计（边界用例/权限粒度/事务与幂等）→ 分级修复 → 回归护栏。

**Architecture:** 并发锁以 `AddonInstaller::withLock()` 私有助手包裹全部六个注册表写入口（install/uninstall/enable/disable/upgrade/refreshMenusAndPermissions——第六个是规划时发现的同级写者），原方法体平移为 `do*` 受保护方法，公开签名零变化；锁键 `arkadmin:addon:{name}`，TTL 600s 防死锁，`block(3)` 快速失败进 AddonException 信封。FrameworkCache 构造器注入 cachePath（默认 `base_path('bootstrap/cache')`），直测指向临时目录 + Artisan mock，测试环境零污染。版本校验收口在 `AddonInfo::fromDir`——所有生命周期入口的必经之路。权限一致性固化为常驻测试：路由 middleware ↔ RbacSeeder ↔ MenuSeeder ↔ 前端 `v-permission` 四方字符串互查。审计线按组件分四组 dispatch 探索子代理，产出分级问题清单（Critical/Important/Minor + file:line + 复现路径），用户逐项核实后修复。

**Tech Stack:** Laravel 13 / PHP 8.3 / PostgreSQL 16 / Pest 3 / Vue3 + Vitest；锁走 `CACHE_STORE`（生产 database、测试 array 驱动均支持原子锁，无需新基建）。

**Spec:** `docs/superpowers/specs/2026-09-17-arkadmin-harness.md` §5 M8 行（全面审计：边界用例、权限粒度、事务与并发；形态=一轮评审+修复）+ `plans/2026-09-17-arkadmin-m7-wrapup.md` 评审轮记录的 M8 顺延项。

**Global Constraints:**
- 信封恒 200（401/422 除外）；锁冲突走 `AddonException` → HTTP code 1 信封
- 测试隔离：fixture 走 `storage_path('framework/addon-fixture')` + `config(['arkadmin.addon_path' => ...])`；前端产物隔离由 `Tests\TestCase` 兜底（admin_path → `storage/framework/admin-test`）
- 不写真实 `admin/` 目录（Task 4 的源码扫描是只读例外，须显式 `config(['arkadmin.admin_path' => dirname(base_path()).'/admin'])` 指回真实目录）
- NiuShop 只学机制禁抄代码；SQL 禁 MySQL 方言（PG only）
- 已定案（grilling 四决策）：范围=插件机制深打磨；遗留只纳入 info.json 版本格式校验；锁=Cache 原子锁；FrameworkCache 直测=路径注入+mock
- 用户逐项核实审计发现后才动手修（M7 评审轮惯例）

## File Structure

```
server/app/Support/Addon/AddonInstaller.php    # 改：+withLock()，六写入口包锁，原体平移 do*
server/app/Support/Addon/FrameworkCache.php    # 改：cachePath 构造注入
server/app/Support/Addon/AddonInfo.php         # 改：version/support_version 格式校验
server/tests/Feature/Addon/AddonLockTest.php          # 新：锁互斥/释放/不串台
server/tests/Feature/Addon/FrameworkCacheTest.php     # 新：条件重建/降级告警
server/tests/Feature/Addon/AddonInfoVersionTest.php   # 新：版本格式 + 捆绑插件合规回归
server/tests/Feature/PermissionConsistencyTest.php    # 新：四方权限串一致性
.scratch/m8-audit/findings-{a,b,c,d}.md       # 审计产出（issue tracker 形态）
docs/superpowers/plans/2026-09-17-arkadmin-m8-wrapup.md
```

---

### Task 1: 生命周期并发锁（withLock 包六写入口）

**Files:** Modify `server/app/Support/Addon/AddonInstaller.php`；Create `server/tests/Feature/Addon/AddonLockTest.php`

**Interfaces:**
- Produces：`AddonInstaller` 公开签名全部不变（`install(): Addon`、`uninstall(string, bool): void`、`enable(string): void`、`disable(string): void`、`upgrade(string, bool): ?AddonInfo`、`refreshMenusAndPermissions(string): void`）；新增 `protected function withLock(string $name, callable $operation): mixed`；锁键 `arkadmin:addon:{name}`。Task 5 审计组 A 以「六入口已互斥」为既成事实。

- [ ] **Step 1: 写失败测试** `AddonLockTest.php`：

```php
<?php

use App\Support\Addon\AddonException;
use App\Support\Addon\AddonInstaller;
use App\Support\Addon\AddonManager;
use App\Support\Addon\Models\Addon;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    config(['arkadmin.addon_path' => storage_path('framework/addon-fixture')]);
});

afterEach(function () {
    app(AddonManager::class)->flushCompiled();
    remove_dir(storage_path('framework/addon-fixture'));
});

it('持锁期间同插件操作被拒，锁释放后恢复', function () {
    make_addon_dir('locka');
    $lock = Cache::lock('arkadmin:addon:locka', 60);
    expect($lock->get())->toBeTrue();

    try {
        app(AddonInstaller::class)->install('locka');
        $this->fail('应当拒绝');
    } catch (AddonException $e) {
        expect($e->getMessage())->toContain('另一操作');
    }
    expect(Addon::find('locka'))->toBeNull();

    $lock->release();
    app(AddonInstaller::class)->install('locka');
    expect(Addon::find('locka'))->not->toBeNull();
});

it('操作失败后锁被释放（可立即重试）', function () {
    make_addon_dir('lockb', ['dependencies' => ['ghost_dep']]);
    try {
        app(AddonInstaller::class)->install('lockb');
        $this->fail('应当拒绝');
    } catch (AddonException) {
        // 依赖缺失被拒：业务错误路径，锁必须在 finally 里已释放
    }
    $reacquire = Cache::lock('arkadmin:addon:lockb', 60);
    expect($reacquire->get())->toBeTrue();
    $reacquire->release();
});

it('不同插件操作互不阻塞', function () {
    make_addon_dir('lockc');
    $lock = Cache::lock('arkadmin:addon:lockd', 60);
    expect($lock->get())->toBeTrue();

    app(AddonInstaller::class)->install('lockc');    // lockd 的锁不挡 lockc
    expect(Addon::find('lockc'))->not->toBeNull();
    $lock->release();
});
```

- [ ] **Step 2: 跑测试确认失败**：`docker compose -f docker/docker-compose.yml exec -u 1000:1000 php sh -c "cd /var/www/server && php artisan test tests/Feature/Addon/AddonLockTest.php"`（预期：首用例 install 成功执行未抛异常 → `$this->fail('应当拒绝')` 红）
- [ ] **Step 3: 实现 `withLock` + 六入口包裹**（`AddonInstaller.php`）：

顶部 use 追加：

```php
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
```

类内新增（建议放在构造器之后）：

```php
    /**
     * 生命周期互斥（M8）：同插件操作串行化——迁移/注册表写回/前端同步的竞态窗口全关。
     * 锁走 CACHE_STORE（生产 database、测试 array 驱动均支持原子锁）；TTL 600s 兜底
     * 防持锁进程崩溃后死锁，迁移再慢也不应超过它；抢不到 3s 内快速失败进信封。
     */
    protected function withLock(string $name, callable $operation): mixed
    {
        $lock = Cache::lock("arkadmin:addon:{$name}", 600);
        try {
            $lock->block(3);
        } catch (LockTimeoutException) {
            // 超时说明锁不在本进程手里，不能 release（会误删他人锁）
            throw new AddonException("插件 [{$name}] 正在被另一操作处理，请稍后再试");
        }
        try {
            return $operation();
        } finally {
            $lock->release();
        }
    }
```

六个公开方法改为薄壳（原方法体逐字平移为同名 `do*` 受保护方法，内容不动）：

```php
    public function install(string $name): Addon
    {
        return $this->withLock($name, fn (): Addon => $this->doInstall($name));
    }

    public function uninstall(string $name, bool $keepData = false): void
    {
        $this->withLock($name, fn () => $this->doUninstall($name, $keepData));
    }

    public function enable(string $name): void
    {
        $this->withLock($name, fn () => $this->doEnable($name));
    }

    public function disable(string $name): void
    {
        $this->withLock($name, fn () => $this->doDisable($name));
    }

    public function upgrade(string $name, bool $force = false): ?AddonInfo
    {
        return $this->withLock($name, fn () => $this->doUpgrade($name, $force));
    }

    public function refreshMenusAndPermissions(string $name): void
    {
        $this->withLock($name, fn () => $this->doRefreshMenusAndPermissions($name));
    }

    protected function doInstall(string $name): Addon { /* 原安装体，逐字平移 */ }
    protected function doUninstall(string $name, bool $keepData = false): void { /* 原卸载体 */ }
    protected function doEnable(string $name): void { /* 原启用体 */ }
    protected function doDisable(string $name): void { /* 原禁用体 */ }
    protected function doUpgrade(string $name, bool $force = false): ?AddonInfo { /* 原升级体 */ }
    protected function doRefreshMenusAndPermissions(string $name): void { /* 原刷新体 */ }
```

注意：`refreshMenusAndPermissions` 虽非生命周期动词，但它写菜单/权限/前端产物，与 install 并发会竞态——一并入锁（计划期发现，超出 grilling 定案的五个，同类同级）。

- [ ] **Step 4: 跑本任务测试 + 全量回归**：先 `... php artisan test tests/Feature/Addon/AddonLockTest.php`（预期 3 绿；首用例含 3s block 等待属预期耗时），再全量 `... php artisan test`（197 基线全绿——公开签名未变，既有用例零改动即应通过）
- [ ] **Step 5: 提交** `git add server/app/Support/Addon/AddonInstaller.php server/tests/Feature/Addon/AddonLockTest.php && git commit -m "feat(m8): serialize addon lifecycle writes with cache atomic lock"`

### Task 2: FrameworkCache 路径注入 + 直测

**Files:** Modify `server/app/Support/Addon/FrameworkCache.php`；Create `server/tests/Feature/Addon/FrameworkCacheTest.php`

**Interfaces:**
- Produces：`FrameworkCache::__construct(?string $cachePath = null)`（null → `base_path('bootstrap/cache')`）。容器解析（`app(FrameworkCache::class)`）行为不变，Task 5 审计组 C 以注入能力为既成事实。

- [ ] **Step 1: 写失败测试** `FrameworkCacheTest.php`：

```php
<?php

use App\Support\Addon\FrameworkCache;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

const CACHE_FIXTURE = 'framework/cache-fixture';

function cache_fixture(string $sub): string
{
    $dir = storage_path(CACHE_FIXTURE.'/'.$sub);
    remove_dir($dir);
    mkdir($dir, 0777, true);

    return $dir;
}

afterEach(function () {
    remove_dir(storage_path(CACHE_FIXTURE));
});

it('空缓存目录不触发任何重建', function () {
    Artisan::shouldReceive('call')->never();
    (new FrameworkCache(cache_fixture('empty')))->rebuild();
});

it('按存在文件精确重建对应命令', function () {
    $dir = cache_fixture('partial');
    file_put_contents($dir.'/config.php', '<?php return [];');
    file_put_contents($dir.'/routes-v1.php', '<?php return [];');
    Artisan::shouldReceive('call')->once()->with('config:cache');
    Artisan::shouldReceive('call')->once()->with('route:cache');
    Artisan::shouldReceive('call')->never()->with('event:cache');
    (new FrameworkCache($dir))->rebuild();
});

it('重建失败降级为告警不抛出', function () {
    $dir = cache_fixture('boom');
    file_put_contents($dir.'/config.php', '<?php return [];');
    Artisan::shouldReceive('call')->andThrow(new RuntimeException('disk full'));
    Log::shouldReceive('warning')->once()->withArgs(fn ($msg) => str_contains($msg, 'config:cache'));
    (new FrameworkCache($dir))->rebuild();    // 走到这里即未抛异常
    expect(true)->toBeTrue();
});
```

- [ ] **Step 2: 跑测试确认失败**：`... php artisan test tests/Feature/Addon/FrameworkCacheTest.php`（预期：`new FrameworkCache($dir)` 报构造器不接受参数的 ArgumentCountError / TypeError 红）
- [ ] **Step 3: 实现**（`FrameworkCache.php` 整体替换为）：

```php
<?php

namespace App\Support\Addon;

use Illuminate\Support\Facades\Artisan;

/** §11 风险对策：config/route/event 缓存在用时自动重建（装/停/卸/升级/ark:sync 共用）。失败降级为告警 */
class FrameworkCache
{
    public function __construct(protected ?string $cachePath = null)
    {
        // 注入点（M8 直测隔离）：默认指真实 bootstrap/cache，测试指向临时目录避免污染
        $this->cachePath ??= base_path('bootstrap/cache');
    }

    public function rebuild(): void
    {
        $rebuild = function (string $command): void {
            try {
                Artisan::call($command);
            } catch (\Throwable $e) {
                logger()->warning("框架缓存重建失败（{$command}）：".$e->getMessage());
            }
        };
        if (is_file($this->cachePath.'/config.php')) {
            $rebuild('config:cache');
        }
        if (is_file($this->cachePath.'/events.php')) {
            $rebuild('event:cache');
        }
        if (glob($this->cachePath.'/routes-*.php')) {
            $rebuild('route:cache');
        }
    }
}
```

- [ ] **Step 4: 跑本任务测试 + 全量回归**（3 新绿；`AddonCacheTest` 等既有 FrameworkCache 间接用例必须仍绿——容器解析走默认路径行为不变）
- [ ] **Step 5: 提交** `git add server/app/Support/Addon/FrameworkCache.php server/tests/Feature/Addon/FrameworkCacheTest.php && git commit -m "feat(m8): injectable cache path with direct framework cache tests"`

### Task 3: info.json 版本格式校验

**Files:** Modify `server/app/Support/Addon/AddonInfo.php`；Create `server/tests/Feature/Addon/AddonInfoVersionTest.php`

**Interfaces:**
- Produces：`AddonInfo::fromDir()` 对 `version`/`support_version` 抛 `AddonException("info.json 字段 {key} 版本号格式无效 [{value}]（须为 x.y.z[-prerelease]）：{file}")`。合法格式 `^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$`（宽松 semver，容忍 `1.0.0-beta.1`；`version_compare` 对该格式的比较语义可靠）。Task 5 审计组 B 以此校验为既成事实。

- [ ] **Step 1: 写失败测试** `AddonInfoVersionTest.php`：

```php
<?php

use App\Support\Addon\AddonException;
use App\Support\Addon\AddonInfo;

afterEach(function () {
    remove_dir(storage_path('framework/addon-fixture'));
});

it('合法版本号通过（含预发布后缀）', function (string $version) {
    $dir = make_addon_dir('verx', ['version' => $version]);
    expect(AddonInfo::fromDir($dir)->version)->toBe($version);
})->with(['0.1.0', '1.2.3', '10.20.30', '1.0.0-beta.1', '2.0.0-rc.1']);

it('非法版本号在清单解析期被拒', function (string $version) {
    $dir = make_addon_dir('very', ['version' => $version]);
    try {
        AddonInfo::fromDir($dir);
        $this->fail('应当拒绝');
    } catch (AddonException $e) {
        expect($e->getMessage())->toContain('version')->toContain('格式无效');
    }
})->with(['v1.0.0', '1.0', '1', '1.0.0.0', 'abc', '1.0.0 beta']);

it('support_version 同样校验', function () {
    $dir = make_addon_dir('verz', ['support_version' => '^1.0']);
    try {
        AddonInfo::fromDir($dir);
        $this->fail('应当拒绝');
    } catch (AddonException $e) {
        expect($e->getMessage())->toContain('support_version')->toContain('格式无效');
    }
});

it('捆绑插件清单全部合规（回归护栏）', function () {
    foreach (glob(base_path('addons/*'), GLOB_ONLYDIR) as $dir) {
        expect(AddonInfo::fromDir($dir))->toBeInstanceOf(AddonInfo::class);
    }
});
```

- [ ] **Step 2: 跑测试确认失败**：`... php artisan test tests/Feature/Addon/AddonInfoVersionTest.php`（预期：非法版本用例红——当前无校验，`v1.0.0` 等直接通过解析）
- [ ] **Step 3: 实现**（`AddonInfo::fromDir` 在 `$json['type'] !== 'app'` 检查之前插入）：

```php
        foreach (['version', 'support_version'] as $key) {
            // version_compare 对 'v1.0'、'1.0'、'1.0 beta' 的比较结果不可靠（M2 评审遗留容忍项，M8 收口）
            if (! preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/', $json[$key])) {
                throw new AddonException(
                    "info.json 字段 {$key} 版本号格式无效 [{$json[$key]}]（须为 x.y.z[-prerelease]）：{$file}"
                );
            }
        }
```

- [ ] **Step 4: 跑本任务测试 + 全量回归**（捆绑四插件 demo/cms/settings/op_logs 均 0.1.0 合规；全量 197 基线 + Task 1/2 增量全绿）
- [ ] **Step 5: 提交** `git add server/app/Support/Addon/AddonInfo.php server/tests/Feature/Addon/AddonInfoVersionTest.php && git commit -m "feat(m8): validate info.json version formats at manifest parsing"`

### Task 4: 权限四方一致性测试

**Files:** Create `server/tests/Feature/PermissionConsistencyTest.php`

**Interfaces:**
- Consumes：`RbacSeeder`/`MenuSeeder`、框架路由表（测试进程内已挂载）、真实 `admin/src` 源码与 `server/addons`（软链→仓库 addons/）的声明文件。
- Produces：常驻回归护栏——「按钮消失」类坑（权限串漂移）从此在 CI 红线暴露。

- [ ] **Step 1: 写测试**（此任务测试先绿后红均可接受：若当前全绿=护栏就位；若红=发现真实漂移，转 Task 6 修复流程）：

```php
<?php

use App\Admin\Models\Menu;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;

/** 前端源码里的 v-permission 字面量（只读扫描；admin/src/addons 是安装产物，排除） */
function frontend_permission_strings(): array
{
    $strings = [];
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(config('arkadmin.admin_path').'/src', FilesystemIterator::SKIP_DOTS)
    );
    foreach ($files as $file) {
        if ($file->isFile() && $file->getExtension() === 'vue'
            && ! str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'addons'.DIRECTORY_SEPARATOR)) {
            preg_match_all("/v-permission=\"'([^']+)'\"/", (string) file_get_contents($file->getPathname()), $m);
            $strings = [...$strings, ...$m[1]];
        }
    }

    return array_values(array_unique($strings));
}

/** 捆绑插件 database/{menus,permissions}.php 声明的权限串（读磁盘声明，不依赖安装态） */
function bundled_addon_permission_strings(): array
{
    $perms = [];
    $walk = function (array $node) use (&$walk, &$perms) {
        if (($node['permission'] ?? '') !== '') {
            $perms[] = $node['permission'];
        }
        foreach ($node['children'] ?? [] as $child) {
            $walk($child);
        }
    };
    foreach (glob(base_path('addons/*/database/menus.php')) as $file) {
        foreach ((array) require $file as $node) {
            if (is_array($node)) {
                $walk($node);
            }
        }
    }
    foreach (glob(base_path('addons/*/database/permissions.php')) as $file) {
        $perms = [...$perms, ...(array) require $file];
    }

    return array_values(array_unique(array_filter($perms, 'is_string')));
}

/** 框架权限全集 = RbacSeeder 种入 DB 的权限串 */
function framework_permission_strings(): array
{
    (new App\Admin\Seeds\RbacSeeder)->run();

    return Permission::where('guard_name', 'admin')->pluck('name')->all();
}

it('路由 permission: 中间件串都在 RBAC 种子权限集内', function () {
    $seeded = framework_permission_strings();
    expect($seeded)->not->toBeEmpty();

    $routePerms = [];
    foreach (Route::getRoutes() as $route) {
        foreach ($route->middleware() as $mw) {
            if (str_starts_with($mw, 'permission:')) {
                $routePerms[] = substr($mw, strlen('permission:'));
            }
        }
    }
    expect($routePerms)->not->toBeEmpty();
    foreach (array_unique($routePerms) as $perm) {
        expect(in_array($perm, $seeded, true))->toBeTrue();
    }
});

it('前端 v-permission 字面量都有权限来源（框架或捆绑插件声明）', function () {
    // 只读例外：显式指回真实后台源码（TestCase 默认隔离到 admin-test，那里没有业务页面）
    config(['arkadmin.admin_path' => dirname(base_path()).'/admin']);
    $declared = array_values(array_unique([
        ...framework_permission_strings(),
        ...bundled_addon_permission_strings(),
    ]));
    expect($declared)->not->toBeEmpty();

    $frontend = frontend_permission_strings();
    expect($frontend)->not->toBeEmpty();
    foreach ($frontend as $perm) {
        expect(in_array($perm, $declared, true))->toBeTrue();
    }
});

it('框架菜单声明的权限串都在权限表内', function () {
    $seeded = framework_permission_strings();
    (new App\Admin\Seeds\MenuSeeder)->run();

    $menuPerms = Menu::where('addon_key', '')->where('permission', '!=', '')->pluck('permission')->all();
    expect($menuPerms)->not->toBeEmpty();
    foreach ($menuPerms as $perm) {
        expect(in_array($perm, $seeded, true))->toBeTrue();
    }
});
```

- [ ] **Step 2: 跑测试**：`... php artisan test tests/Feature/PermissionConsistencyTest.php`。三种可能结果与处置：
  - 全绿 → 护栏就位，进 Step 3
  - 红 且断言合理 → 真实漂移：记入 `.scratch/m8-audit/findings-e.md`（级别 Important），按 Task 6 修复流程走（先不改代码）
  - 红 且是测试自身错误（如正则漏匹配）→ 修测试
- [ ] **Step 3: 全量回归 + 提交** `git add server/tests/Feature/PermissionConsistencyTest.php && git commit -m "feat(m8): four-way permission string consistency guard"`

### Task 5: 全链 fresh-eyes 审计（评审轮主体）

**Files:** Create `.scratch/m8-audit/findings-{a,b,c,d}.md`（issue tracker 形态，见 `docs/agents/issue-tracker.md`）

**Interfaces:**
- Consumes：Task 1–4 已合入的既成事实（六入口互斥、cachePath 注入、版本校验、一致性护栏）。
- Produces：分级问题清单（Critical/Important/Minor，每条含 file:line + 复现路径或反证），供 Task 6 消费。

- [ ] **Step 1: 四组并行 dispatch**（探索子代理，研究型，不改代码）。每组同一报告骨架：**问题 | 级别 | 位置 file:line | 复现路径（怎么触发）/ 反证（为什么安全）**。禁止泛泛而谈，每条须可复核。统一声明已知事实免重复报告：生命周期六入口已有 Cache 原子锁互斥；`AddonInfo` 已校验版本格式与插件名正则；`mustExistOnDisk` 已卡 `^[a-z][a-z0-9_]*$`；Controller 写端点已捕 `\Throwable`；`upgrade` 已做 `assertInstallable` 与禁用态不注册 provider；FrameworkSync 刻意只捕 AddonException（响亮失败，有注释固化）。

  **组 A 安装器与生命周期**（`server/app/Support/Addon/AddonInstaller.php` 全文 + `server/tests/TestCase.php`）。检查单：事务边界——`install` 的 `DB::transaction` 覆盖范围 vs `uninstall` 的菜单/权限/注册行三连删无事务，半程失败留下什么态、可否重试；`enable`/`disable` 的 `update` 与钩子顺序失败窗口；`syncFrontend` 原子替换三步（tmp→bak→rename）每步失败的现场；`detachListeners` 的 `Event::forget` 窗口内新事件注册的丢失；`hook()` 异常在 install（事务内，回滚）vs disable（事务外，半禁用态）的语义差异；`purgeFrontend` 静默失败后果。

  **组 B 清单/扫描/依赖**（`AddonInfo.php`、`AddonManager.php`、`AddonDependency.php`）。检查单：畸形清单边界（超长字段、`dependencies` 自引用、`type` 大小写）；`scan()` 对半损坏目录（缺 src、info.json 不可读）的行为；环检测 DFS 的 visited 剪枝正确性与最坏复杂度；`dependents()` 的 scan 复用与注册表差异口径；`flushCompiled` 与磁盘变更的时序窗口；编译缓存损坏文件的自愈路径。

  **组 C 同步与命令**（`FrameworkSync.php`、`FrameworkCache.php`、`DatabaseSeeder.php`、`server/app/Support/Addon/Console/*.php` 全部命令）。检查单：`addon:*` 与 `ark:sync` 的参数边界（name 与 `--all` 互斥、不存在的插件名、空扫描集）；批量操作的失败聚合与退出码；`--force` 语义在各命令间的一致性；`ArkSyncCommand` 输出与 `FrameworkSync` 返回结构的对应；命令输出文案与实际行为的漂移。

  **组 D HTTP 层、前端与生成器**（`AddonController.php`、`server/routes/api.php`、`admin/src/app/{api,utils}/addon.ts`、`admin/src/app/views/system/addon/index.vue`、`server/app/Console/Commands` 下 ark:crud 与 `server/stubs/crud/*`）。检查单：信封契约（恒 200，401/422 除外）逐端点核对；`keep_data` 查询参数到 `uninstall` 的流转；前端 `AddonRow` 类型与后端 `row()` 实际字段的漂移；`confirmUninstall` 三选语义与后端行为对齐；ark:crud 生成产物的权限串/菜单标记对幂等性；`--force` 重复生成安全性。

- [ ] **Step 2: 汇总归档**：四份 findings 合并为分级总表（含 Task 4 Step 2 若发现的 findings-e），写入 `.scratch/m8-audit/findings.md`，提交 `git add .scratch/m8-audit && git commit -m "docs(m8): graded audit findings across addon stack"`
- [ ] **Step 3: 用户逐项核实**（M7 惯例）：把总表呈给用户，逐条确认 真实缺陷 / 误报（记反证）/ 刻意行为（记理由）。核实结果标注进总表。

### Task 6: 修复轮

**Files:** 按核实清单逐条定（预期落在 Task 5 四组的文件域内）

**Interfaces:**
- Consumes：`.scratch/m8-audit/findings.md` 已核实条目（每条有 id 与级别）。
- Produces：每条 Critical/Important 修复配回归测试；Minor 记录进 wrapup（用户点名才修）。

- [ ] **Step 1: 按级别排序逐条修复**，每条走完整小循环（不可跳步）：
  1. 写失败测试复现该问题（fixture 惯例同 Task 1；测试名带 finding id，如 `it('AUDIT-B3 环检测自引用清单被拒', ...)`）
  2. 跑红：`... php artisan test --filter="AUDIT-<id>"`
  3. 最小修复（不做修复清单之外的顺手重构）
  4. 跑绿 + 全量回归
  5. 提交 `fix(m8): <finding id> <一句话根因与修法>`（一条一提交，回滚粒度对齐）
- [ ] **Step 2: 修复全量回归**：`... php artisan test` 全绿 + `cd admin && npm test`（若涉及前端条目）+ `npx vue-tsc -b`
- [ ] **Step 3: 误报与刻意行为的反证记录**追加进 findings.md（下一位审计者不再踩同一坑），提交 `docs(m8): record audit false positives and intentional behaviors`

### Task 7: 收尾验收

**Files:** Modify `AGENTS.md`、`docs/superpowers/specs/2026-09-17-arkadmin-harness.md`（§5 M8 行标注）；Create `docs/superpowers/plans/2026-09-17-arkadmin-m8-wrapup.md`

- [ ] **Step 1: dev 库实测**（真实链路，非测试库）：
  1. 并发锁实测：`TOKEN=$(curl -s ... login ...)` 后后台并行双请求 `curl -s -X PUT .../addons/demo -d '{"action":"disable"}' & curl -s -X PUT .../addons/demo -d '{"action":"enable"}' &` → 恰一成功一 code 1「另一操作」，且终态合法（无半禁用）
  2. `php artisan ark:sync` 幂等跳过 ✓；`php artisan addon:upgrade --all` 四插件「已是最新」✓
  3. 畸形版本防护实测：临时把 `addons/demo/info.json` version 改 `v0.2.0` → `addon:upgrade demo` 报「格式无效」→ 还原
- [ ] **Step 2: 全量回归四件套**：`php artisan test` 全绿、`npm test` 全绿、`vue-tsc -b` 通过、`npm run build` 成功
- [ ] **Step 3: AGENTS.md 更新**：插件 CLI 小节补一句「生命周期操作同插件互斥（并发触发得『另一操作』提示，3s 快速失败）」；若审计修复改变了任何命令行为则同步条目
- [ ] **Step 4: harness spec §5**：M8 行标注「完成（见 wrapup）」
- [ ] **Step 5: wrapup**：完成标志（四线：锁/直测/校验/审计修复）、测试数字、dev 实测记录、审计分级统计（C/I/M 各几、误报几、刻意行为几）、实现要点与偏差（含 refreshMenusAndPermissions 入锁的计划偏差）、待办（M9 交互打磨）
- [ ] **Step 6: 提交** `git commit -m "docs(m8): acceptance records and workspace guide updates"`

## 范围外（grilling 定案）

M5 表单类遗留（图片列选择器联动/jsonb 编辑器/关联字段下拉）、dist chunk 瘦身、`ark:make:addon` 脚手架、素材库插件化、zip 打包、依赖版本约束、boot 期自动升级、M9 交互打磨、M10 视觉。
