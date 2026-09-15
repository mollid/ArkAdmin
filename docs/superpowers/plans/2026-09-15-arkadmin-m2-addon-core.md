# ArkAdmin M2 插件系统核心 — 实施计划

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 实现设计文档 §6 插件系统核心——引导、生命周期、CLI、菜单注入、编译缓存——并以 `demo` 空插件走通 Pest 全回路测试与手工验收（装/停/卸）。

**Architecture:** 框架侧 `App\Support\Addon\` 五件套：`AddonInfo`（清单解析校验）、`AddonManager`（磁盘扫描 + `Addons\` 自动加载 + 引导 + 编译缓存）、`AddonInstaller`（install/uninstall/enable/disable 编排 + 菜单/权限同步）、`AddonServiceProvider` 基类（插件继承，挂路由/监听）、`Contracts\Lifecycle`（插件生命周期钩子）。引导经 `AddonBootServiceProvider` 挂入 `bootstrap/app.php`；插件目录在仓库根 `addons/`，`server/addons` 为软链（docker 已挂载仓库根到 `/var/www`，链路通）。

**Tech Stack:** Laravel 13（既有骨架）、spatie/laravel-permission 6（`module` 列已就绪）、PostgreSQL 16、Pest 4。无新增 composer 依赖。

**Spec:** `docs/superpowers/specs/2026-09-14-arkadmin-design.md` §5.4（addons 表）、§5.5（事件埋点）、§6（插件系统全节）、§10 M2 行、§11 缓存风险对策。M3 范围（前端复制/删除、组件缺失兜底、i18n 合并，§6.6）**明确不在本计划内**。

## Global Constraints

- 统一响应信封 `{"code": 0, "data": {}, "msg": "ok"}`；HTTP 恒 200，仅 401/422 例外（M1 既有约定）
- 权限串约定：插件 `addon.<key>.<controller>.<action>`；框架 `system.<controller>.<action>`
- 所有业务表用 PG 原生类型（jsonb/text[]），禁止 MySQL 逗号分隔习惯
- 插件表名前缀 `<key>_`
- NiuShop 只学机制，零复制代码；可借鉴 Apache-2.0 的 FastAdmin
- 后端测试在 docker 内跑：`docker compose -f docker/docker-compose.yml exec -u 1000:1000 php sh -c "cd /var/www/server && php artisan test"`（测试库 `arkadmin_test`，与开发库隔离）
- 设计文档 §10 M2 完成标志：**手工建一个空插件走完装/停/卸**
- 前端 `admin/` 本里程碑不动（M3 才做前端同步）

## 设计勘误（本计划对设计文档的两处落地修正，T8 回写文档）

1. **§6.4 "插件命名空间已由根 composer.json 的 psr-4 映射覆盖"不成立**：§6.1 的 `src/` 布局无法用单一 psr-4 前缀静态覆盖（`Addons\Cms\X` 会映射到 `addons/Cms/X.php` 而非 `addons/cms/src/X.php`）。落地改为：**框架引导时注册 `Addons\` 前缀的 spl autoloader**，映射 `Addons\<key>\<Rest>` → `addons/<key>/src/<Rest>.php`。插件作者零 composer 配置，用户可见契约（§6.1 目录结构）不变。
2. **§6.1 示例命名空间 `Addons\Cms\` 调整为 `Addons\cms\`**（命名空间段 = 插件目录名 = info.json name，全小写，保证 PSR-4 机械映射一致；PHP 命名空间合法且 Composer/框架均无大小写转换）。info.json 校验强制 name 匹配 `^[a-z][a-z0-9_]*$`。

## File Structure

```
server/
├── bootstrap/app.php                              # 改：withProviders(AddonBootServiceProvider) + withCommands(7 个 addon 命令)
├── config/arkadmin.php                            # 新：version + addon_path
├── database/migrations/2026_09_15_000001_create_addons_table.php   # 新
├── app/
│   ├── Providers/AddonBootServiceProvider.php     # 新：绑定单例 + 引导
│   ├── Admin/
│   │   ├── Events/AdminLoginSuccessed.php         # 新：§5.5 埋点
│   │   ├── Http/Controllers/AuthController.php    # 改：登录成功 dispatch 事件
│   │   └── Services/MenuService.php               # 改：禁用插件菜单不渲染
│   └── Support/Addon/
│       ├── AddonException.php                     # 新
│       ├── AddonInfo.php                          # 新：info.json 解析与校验
│       ├── AddonManager.php                       # 新：扫描/引导/autoloader/编译缓存
│       ├── AddonInstaller.php                     # 新：生命周期编排 + 菜单/权限同步
│       ├── AddonServiceProvider.php               # 新：插件 provider 基类
│       ├── Contracts/Lifecycle.php                # 新：插件钩子接口
│       ├── Models/Addon.php                       # 新：addons 注册表模型
│       └── Console/                               # 新：7 个 artisan 命令
├── addons -> ../addons                            # 新：软链（§4）
└── tests/
    ├── Pest.php                                   # 改：追加插件测试公共辅助函数
    └── Feature/Addon/                             # 新：6 个测试文件
addons/                                            # 新：插件根目录
└── demo/                                          # 新：M2 验收空插件（一张表、一个接口、一个菜单）
```

责任边界：`AddonManager` 只读（扫描/引导/缓存编译，不改数据库注册表）；`AddonInstaller` 唯一写注册表的角色并编排生命周期；插件 provider 基类自定位插件目录（反射类文件路径），插件侧零样板配置。

---

### Task 1: 基础层 — 配置、addons 注册表、目录与软链

**Files:**
- Create: `server/config/arkadmin.php`
- Create: `server/database/migrations/2026_09_15_000001_create_addons_table.php`
- Create: `server/app/Support/Addon/Models/Addon.php`
- Create: `addons/.gitkeep`、软链 `server/addons`
- Test: `server/tests/Feature/Addon/AddonRegistryTest.php`

**Interfaces:**
- Consumes: 无（起点任务）
- Produces: `config('arkadmin.version')`（string，`AddonInfo::isSupported` 与安装校验用）；`App\Support\Addon\Models\Addon`（Eloquent，主键 `name` string，字段 `name/title/version/config/enabled/install_time`，casts `config=array, enabled=bool, install_time=datetime`）——后续所有任务的注册表读写入口

- [ ] **Step 1: 写失败测试**

```php
<?php

use App\Support\Addon\Models\Addon;
use Illuminate\Support\Facades\Schema;

it('创建 addons 注册表', function () {
    expect(Schema::hasTable('addons'))->toBeTrue();
});

it('注册表模型以 name 为主键并正确 cast', function () {
    $record = Addon::create([
        'name' => 'alpha', 'title' => 'A', 'version' => '0.1.0',
        'config' => ['k' => 'v'], 'enabled' => true, 'install_time' => now(),
    ]);
    expect($record->getKey())->toBe('alpha')
        ->and($record->enabled)->toBeTrue()
        ->and($record->config)->toBe(['k' => 'v'])
        ->and($record->install_time)->toBeInstanceOf(Illuminate\Support\Carbon::class);
});

it('框架版本配置就绪', function () {
    expect(config('arkadmin.version'))->toBeString()
        ->and(config('arkadmin.version'))->not->toBe('')
        ->and(is_dir(base_path('addons')))->toBeTrue()
        ->and(is_dir(base_path('addons')) && realpath(base_path('addons')) !== false)->toBeTrue();
});
```

- [ ] **Step 2: 跑测试确认失败**

Run: `docker compose -f docker/docker-compose.yml exec -u 1000:1000 php sh -c "cd /var/www/server && php artisan test --filter=AddonRegistryTest"`
Expected: FAIL（`addons` 表不存在 / 类不存在）

- [ ] **Step 3: 实现**

`server/config/arkadmin.php`：

```php
<?php

// ArkAdmin 框架级配置（§6.2 support_version 与此处的 version 比较）
return [
    'version' => env('ARKADMIN_VERSION', '0.1.0'),

    // 插件根目录；仓库根 addons/，server/addons 为其软链（§4）
    'addon_path' => env('ARKADMIN_ADDON_PATH', base_path('addons')),
];
```

`server/database/migrations/2026_09_15_000001_create_addons_table.php`：

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('addons', function (Blueprint $table) {
            $table->string('name', 64)->primary();
            $table->string('title')->default('');
            $table->string('version', 32)->default('');
            $table->jsonb('config')->nullable();
            $table->boolean('enabled')->default(false);
            $table->timestamp('install_time')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('addons');
    }
};
```

`server/app/Support/Addon/Models/Addon.php`：

```php
<?php

namespace App\Support\Addon\Models;

use Illuminate\Database\Eloquent\Model;

/** addons 注册表（§5.4）。name 即插件目录名/info.json name */
class Addon extends Model
{
    protected $table = 'addons';

    protected $primaryKey = 'name';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = ['name', 'title', 'version', 'config', 'enabled', 'install_time'];

    protected function casts(): array
    {
        return ['config' => 'array', 'enabled' => 'boolean', 'install_time' => 'datetime'];
    }
}
```

目录与软链（工作区根执行）：

```bash
mkdir -p addons && touch addons/.gitkeep
ln -sfn ../addons server/addons
```

- [ ] **Step 4: 跑测试确认通过**

Run: `docker compose -f docker/docker-compose.yml exec -u 1000:1000 php sh -c "cd /var/www/server && php artisan test --filter=AddonRegistryTest"`
Expected: PASS（3 项）

- [ ] **Step 5: 全量回归 + 提交**

```bash
docker compose -f docker/docker-compose.yml exec -u 1000:1000 php sh -c "cd /var/www/server && php artisan test"
git add server/config/arkadmin.php server/database/migrations server/app/Support/Addon server/addons addons
git commit -m "feat(m2): arkadmin config, addons registry table and model"
```

---

### Task 2: 清单层 — AddonException / AddonInfo 解析校验

**Files:**
- Create: `server/app/Support/Addon/AddonException.php`
- Create: `server/app/Support/Addon/AddonInfo.php`
- Modify: `server/tests/Pest.php`（追加公共辅助函数，后续所有任务共用）
- Test: `server/tests/Feature/Addon/AddonInfoTest.php`

**Interfaces:**
- Consumes: `config('arkadmin.addon_path')`（T1）
- Produces:
  - `AddonException extends \RuntimeException`
  - `AddonInfo`（readonly VO）：属性 `string $name, string $title, string $description, string $version, string $type, string $supportVersion, array $dependencies, string $dir`；静态 `fromDir(string $dir): self`（校验失败抛 `AddonException`）；方法 `providerClass(): string`（`Addons\{name}\AddonServiceProvider`）、`addonClass(): string`（`Addons\{name}\Addon`）、`isSupported(string $frameworkVersion): bool`、`routeFile(): string`、`migrationPath(): string`、`menusFile(): string`、`permissionsFile(): string`
  - Pest 辅助：`make_addon_dir(string $name, array $infoOverrides = [], bool $withSrc = true): string`、`remove_dir(string $dir): void`、`admin_token(): string`、`super_admin(): Admin`、`install_demo(): void`、`remount_routes(): void`、`menu_tree_names(array $nodes): array`

- [ ] **Step 1: 在 `server/tests/Pest.php` 追加公共辅助**

在现有 `uses(...)` 行之后追加（完整文件其余部分保持不变）：

```php
// —— M2 插件系统测试公共辅助 ——

/** 登录超管拿 Bearer token */
function admin_token(): string
{
    return test()->postJson('/api/admin/auth/login', ['username' => 'admin', 'password' => '123456'])
        ->assertOk()->json('data.token');
}

function super_admin(): \App\Admin\Models\Admin
{
    return \App\Admin\Models\Admin::where('username', 'admin')->firstOrFail();
}

/** 安装仓库内置 demo 插件（仅 M2 验收插件在 addons/demo） */
function install_demo(): void
{
    app(\App\Support\Addon\AddonInstaller::class)->install('demo');
}

/** 重建路由表：模拟新进程启动时的真实挂载结果（框架 api 路由 + 已启用插件路由） */
function remount_routes(): void
{
    app('router')->setRoutes(new \Illuminate\Routing\RouteCollection());
    \Illuminate\Support\Facades\Route::middleware('api')->prefix('api')->group(base_path('routes/api.php'));
    foreach (app(\App\Support\Addon\AddonManager::class)->enabledInfos() as $info) {
        $class = $info->providerClass();
        if (class_exists($class)) {
            app($class)->mountRoutes();
        }
    }
}

/** 递归展平菜单树取 name 集合 */
function menu_tree_names(array $nodes): array
{
    $names = [];
    foreach ($nodes as $node) {
        $names[] = $node['name'];
        $names = array_merge($names, menu_tree_names($node['children'] ?? []));
    }
    return $names;
}

/** 在测试固定目录下生成插件 fixture（合法最小 info.json + 可选 src/） */
function make_addon_dir(string $name, array $infoOverrides = [], bool $withSrc = true): string
{
    $dir = storage_path('framework/addon-fixture/'.uniqid($name.'_'));
    if ($withSrc) {
        mkdir($dir.'/src', 0777, true);
    } else {
        mkdir($dir, 0777, true);
    }
    file_put_contents($dir.'/info.json', json_encode(array_merge([
        'name' => $name,
        'title' => '测试插件',
        'description' => '',
        'version' => '0.1.0',
        'type' => 'app',
        'support_version' => '0.1.0',
        'dependencies' => [],
    ], $infoOverrides), JSON_UNESCAPED_UNICODE));

    return $dir;
}

function remove_dir(string $dir): void
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
```

- [ ] **Step 2: 写失败测试** — `server/tests/Feature/Addon/AddonInfoTest.php`

```php
<?php

use App\Support\Addon\AddonException;
use App\Support\Addon\AddonInfo;

afterEach(function () {
    remove_dir(storage_path('framework/addon-fixture'));
});

it('解析合法 info.json 并提供路径/类名派生', function () {
    $info = AddonInfo::fromDir(make_addon_dir('alpha'));
    expect($info->name)->toBe('alpha')
        ->and($info->title)->toBe('测试插件')
        ->and($info->version)->toBe('0.1.0')
        ->and($info->supportVersion)->toBe('0.1.0')
        ->and($info->dependencies)->toBe([])
        ->and($info->providerClass())->toBe('Addons\alpha\AddonServiceProvider')
        ->and($info->addonClass())->toBe('Addons\alpha\Addon')
        ->and($info->routeFile())->toBe($info->dir.'/routes/admin.php')
        ->and($info->migrationPath())->toBe($info->dir.'/database/migrations')
        ->and($info->menusFile())->toBe($info->dir.'/database/menus.php')
        ->and($info->permissionsFile())->toBe($info->dir.'/database/permissions.php');
});

it('support_version 与框架版本比较', function () {
    $info = AddonInfo::fromDir(make_addon_dir('alpha', ['support_version' => '0.2.0']));
    expect($info->isSupported('0.2.0'))->toBeTrue()
        ->and($info->isSupported('0.1.0'))->toBeFalse()
        ->and($info->isSupported('1.0.0'))->toBeTrue();
});

it('缺失 info.json 抛异常', function () {
    $dir = storage_path('framework/addon-fixture/bare_'.uniqid());
    mkdir($dir, 0777, true);
    expect(fn () => AddonInfo::fromDir($dir))->toThrow(AddonException::class);
});

it('插件名必须匹配 ^[a-z][a-z0-9_]*$', function () {
    expect(fn () => AddonInfo::fromDir(make_addon_dir('alpha', ['name' => 'Bad-Name'])))->toThrow(AddonException::class)
        ->and(fn () => AddonInfo::fromDir(make_addon_dir('alpha', ['name' => ''])))->toThrow(AddonException::class);
});

it('type 当前仅支持 app', function () {
    expect(fn () => AddonInfo::fromDir(make_addon_dir('alpha', ['type' => 'theme'])))->toThrow(AddonException::class);
});

it('缺少必填字段抛异常', function () {
    expect(fn () => AddonInfo::fromDir(make_addon_dir('alpha', ['title' => null])))->toThrow(AddonException::class)
        ->and(fn () => AddonInfo::fromDir(make_addon_dir('alpha', ['support_version' => null])))->toThrow(AddonException::class);
});

it('缺少 src 目录抛异常', function () {
    expect(fn () => AddonInfo::fromDir(make_addon_dir('alpha', withSrc: false)))->toThrow(AddonException::class);
});

it('dependencies 非数组抛异常', function () {
    expect(fn () => AddonInfo::fromDir(make_addon_dir('alpha', ['dependencies' => 'cms'])))->toThrow(AddonException::class);
});
```

- [ ] **Step 3: 跑测试确认失败**

Run: `docker compose -f docker/docker-compose.yml exec -u 1000:1000 php sh -c "cd /var/www/server && php artisan test --filter=AddonInfoTest"`
Expected: FAIL（类不存在）

- [ ] **Step 4: 实现**

`server/app/Support/Addon/AddonException.php`：

```php
<?php

namespace App\Support\Addon;

class AddonException extends \RuntimeException
{
}
```

`server/app/Support/Addon/AddonInfo.php`：

```php
<?php

namespace App\Support\Addon;

/**
 * info.json 清单 VO（§6.2）。所有插件操作以它为唯一入口，
 * 校验失败的插件不允许进入安装/引导流程。
 */
class AddonInfo
{
    public function __construct(
        public readonly string $name,
        public readonly string $title,
        public readonly string $description,
        public readonly string $version,
        public readonly string $type,
        public readonly string $supportVersion,
        public readonly array $dependencies,
        public readonly string $dir,
    ) {
    }

    public static function fromDir(string $dir): self
    {
        $dir = rtrim($dir, '/');
        $file = $dir.'/info.json';
        if (! is_file($file)) {
            throw new AddonException("插件目录缺少 info.json：{$dir}");
        }
        $json = json_decode((string) file_get_contents($file), true);
        if (! is_array($json)) {
            throw new AddonException("info.json 不是合法 JSON 对象：{$file}");
        }
        foreach (['name', 'title', 'version', 'type', 'support_version'] as $key) {
            if (! isset($json[$key]) || ! is_string($json[$key]) || $json[$key] === '') {
                throw new AddonException("info.json 缺少必填字段 {$key}：{$file}");
            }
        }
        if (! preg_match('/^[a-z][a-z0-9_]*$/', $json['name'])) {
            throw new AddonException("插件名必须匹配 ^[a-z][a-z0-9_]*$：{$json['name']}");
        }
        if ($json['type'] !== 'app') {
            throw new AddonException("暂不支持的插件类型 [{$json['type']}]：{$file}");
        }
        if (! is_dir($dir.'/src')) {
            throw new AddonException("插件缺少 src 目录：{$dir}");
        }
        $dependencies = $json['dependencies'] ?? [];
        if (! is_array($dependencies)) {
            throw new AddonException("dependencies 必须为字符串数组：{$file}");
        }

        return new self(
            $json['name'],
            $json['title'],
            (string) ($json['description'] ?? ''),
            $json['version'],
            $json['type'],
            $json['support_version'],
            array_values(array_filter($dependencies, 'is_string')),
            $dir,
        );
    }

    public function providerClass(): string
    {
        return "Addons\\{$this->name}\\AddonServiceProvider";
    }

    public function addonClass(): string
    {
        return "Addons\\{$this->name}\\Addon";
    }

    /** support_version：插件要求的框架最低版本（§6.2） */
    public function isSupported(string $frameworkVersion): bool
    {
        return version_compare($frameworkVersion, $this->supportVersion, '>=');
    }

    public function routeFile(): string
    {
        return $this->dir.'/routes/admin.php';
    }

    public function migrationPath(): string
    {
        return $this->dir.'/database/migrations';
    }

    public function menusFile(): string
    {
        return $this->dir.'/database/menus.php';
    }

    public function permissionsFile(): string
    {
        return $this->dir.'/database/permissions.php';
    }
}
```

- [ ] **Step 5: 跑测试确认通过**

Run: `docker compose -f docker/docker-compose.yml exec -u 1000:1000 php sh -c "cd /var/www/server && php artisan test --filter=AddonInfoTest"`
Expected: PASS（8 项）

- [ ] **Step 6: 提交**

```bash
git add server/app/Support/Addon server/tests/Pest.php server/tests/Feature/Addon
git commit -m "feat(m2): addon info.json parsing and validation"
```

---

### Task 3: 引导层 — Lifecycle 契约、AddonManager、provider 基类、demo 插件骨架

**Files:**
- Create: `server/app/Support/Addon/Contracts/Lifecycle.php`
- Create: `server/app/Support/Addon/AddonManager.php`
- Create: `server/app/Support/Addon/AddonServiceProvider.php`
- Create: `server/app/Providers/AddonBootServiceProvider.php`
- Modify: `server/bootstrap/app.php`（加 `->withProviders([...])`）
- Create: `addons/demo/info.json`、`addons/demo/src/Addon.php`、`addons/demo/src/AddonServiceProvider.php`、`addons/demo/src/Http/Controllers/NoteController.php`、`addons/demo/routes/admin.php`
- Test: `server/tests/Feature/Addon/BootTest.php`

**Interfaces:**
- Consumes: `AddonInfo`（T2）、`Addon` 模型（T1）
- Produces:
  - `Contracts\Lifecycle`：`install(): void; uninstall(): void; enable(): void; disable(): void; upgrade(string $fromVersion): void`
  - `AddonManager`：`addonPath(): string`、`scan(): array<string, AddonInfo>`、`enabledInfos(): array<string, AddonInfo>`、`boot(): void`（幂等，可重复调用）、`compiledFile(): string`、`isCompiled(): bool`、`compile(): void`、`flushCompiled(): void`
  - `AddonServiceProvider`（abstract，插件继承）：`public function mountRoutes(): void`（路由前缀 `api/admin/addon/<key>` + 中间件 `auth:admin`）；`protected array $listen = []`（事件 => 监听器数组）
  - 插件路由约定：`routes/admin.php` 内以相对路径定义（如 `Route::get('notes', ...)`），由基类包前缀与中间件

- [ ] **Step 1: 写失败测试** — `server/tests/Feature/Addon/BootTest.php`

```php
<?php

use App\Support\Addon\AddonManager;
use App\Support\Addon\Models\Addon;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

it('Addons 前缀类可被自动加载到 addons/<key>/src', function () {
    app(AddonManager::class)->boot();
    expect(class_exists(\Addons\demo\AddonServiceProvider::class))->toBeTrue()
        ->and(class_exists(\Addons\demo\Addon::class))->toBeTrue()
        ->and(class_exists(\Addons\demo\Http\Controllers\NoteController::class))->toBeTrue();
});

it('启用插件的路由在引导时挂载到 /api/admin/addon/<key>', function () {
    Addon::create([
        'name' => 'demo', 'title' => '演示插件', 'version' => '0.1.0',
        'enabled' => true, 'install_time' => now(),
    ]);
    app(AddonManager::class)->boot();
    $route = app('router')->getRoutes()->match(
        Request::create('http://localhost/api/admin/addon/demo/notes')
    );
    expect($route->getControllerClass())->toBe(\Addons\demo\Http\Controllers\NoteController::class)
        ->and($route->getActionName())->toContain('index');
});

it('未启用插件的路由不挂载', function () {
    app(AddonManager::class)->boot();
    expect(fn () => app('router')->getRoutes()->match(
        Request::create('http://localhost/api/admin/addon/demo/notes')
    ))->toThrow(NotFoundHttpException::class);
});

it('boot 幂等：重复调用不重复注册 provider', function () {
    Addon::create([
        'name' => 'demo', 'title' => '演示插件', 'version' => '0.1.0',
        'enabled' => true, 'install_time' => now(),
    ]);
    app(AddonManager::class)->boot();
    $count = count(app('router')->getRoutes()->getByName('whatever') ?? []);
    $routesBefore = count(app('router')->getRoutes());
    app(AddonManager::class)->boot();
    expect(count(app('router')->getRoutes()))->toBe($routesBefore);
});
```

（注：本任务 demo_notes 表尚未创建，因此用路由匹配断言挂载结果而非发 HTTP 请求；HTTP 级验证在 Task 4 安装器就绪后进行。）

- [ ] **Step 2: 跑测试确认失败**

Run: `docker compose -f docker/docker-compose.yml exec -u 1000:1000 php sh -c "cd /var/www/server && php artisan test --filter=BootTest"`
Expected: FAIL

- [ ] **Step 3: 实现**

`server/app/Support/Addon/Contracts/Lifecycle.php`：

```php
<?php

namespace App\Support\Addon\Contracts;

/** 插件生命周期钩子（§6.3）。全部钩子在对应迁移/注册表操作之后由框架调用 */
interface Lifecycle
{
    /** 迁移后执行：种子数据、初始化配置 */
    public function install(): void;

    /** 迁移回滚后执行：清理（--keep-data 卸载时同样调用） */
    public function uninstall(): void;

    public function enable(): void;

    public function disable(): void;

    public function upgrade(string $fromVersion): void;
}
```

`server/app/Support/Addon/AddonManager.php`：

```php
<?php

namespace App\Support\Addon;

use App\Support\Addon\Models\Addon;
use Illuminate\Contracts\Foundation\Application;

/**
 * 插件引导与发现（§6.4）：只读组件——扫描磁盘、读注册表、编译缓存、注册 provider。
 * 注册表的写操作全部在 AddonInstaller。
 */
class AddonManager
{
    protected static bool $autoloaderRegistered = false;

    /** @var array<class-string, true> 本次进程已注册的 provider，保证 boot 幂等 */
    protected array $loaded = [];

    public function __construct(protected Application $app)
    {
    }

    public function addonPath(): string
    {
        return rtrim((string) config('arkadmin.addon_path', base_path('addons')), '/');
    }

    public function compiledFile(): string
    {
        return $this->app->bootstrapPath('cache/addons.php');
    }

    public function isCompiled(): bool
    {
        return is_file($this->compiledFile());
    }

    /** @return array<string, AddonInfo> 磁盘插件清单（key = 插件名），坏清单跳过并记日志 */
    public function scan(): array
    {
        $infos = [];
        foreach (glob($this->addonPath().'/*/info.json') ?: [] as $file) {
            try {
                $info = AddonInfo::fromDir(dirname($file));
            } catch (AddonException $e) {
                logger()->warning('跳过无效插件：'.$e->getMessage());
                continue;
            }
            $infos[$info->name] = $info;
        }
        ksort($infos);

        return $infos;
    }

    /** @return array<string, AddonInfo> 注册表中已启用且磁盘健在的插件 */
    public function enabledInfos(): array
    {
        $infos = [];
        foreach (Addon::query()->where('enabled', true)->get() as $record) {
            try {
                $infos[$record->name] = AddonInfo::fromDir($this->addonPath().'/'.$record->name);
            } catch (AddonException $e) {
                logger()->warning("已启用插件 [{$record->name}] 无法加载：".$e->getMessage());
            }
        }

        return $infos;
    }

    /** 框架引导入口：注册 Addons\ 自动加载并注册全部已启用插件 provider */
    public function boot(): void
    {
        $this->registerAutoloader();
        foreach ($this->loadableInfos() as $info) {
            $class = $info->providerClass();
            if (isset($this->loaded[$class])) {
                continue;
            }
            if (! class_exists($class)) {
                logger()->warning("插件 [{$info->name}] 缺少 {$class}，跳过加载");
                continue;
            }
            $this->loaded[$class] = true;
            $this->app->register($class);
        }
    }

    /** @return list<AddonInfo> 编译缓存优先，缓存缺失时按注册表现算 */
    protected function loadableInfos(): array
    {
        if (! $this->isCompiled()) {
            return array_values($this->enabledInfos());
        }
        $infos = [];
        foreach (require $this->compiledFile() as $entry) {
            try {
                $infos[] = AddonInfo::fromDir($entry['dir']);
            } catch (AddonException $e) {
                logger()->warning('编译缓存中的插件无法加载：'.$e->getMessage());
            }
        }

        return $infos;
    }

    /** Addons\<key>\<Rest> → addons/<key>/src/<Rest>.php（目录名 = 插件名，见计划「设计勘误」） */
    protected function registerAutoloader(): void
    {
        if (static::$autoloaderRegistered) {
            return;
        }
        static::$autoloaderRegistered = true;
        spl_autoload_register(function (string $class): void {
            if (! str_starts_with($class, 'Addons\\')) {
                return;
            }
            $segments = explode('\\', $class);
            if (count($segments) < 3) {
                return;
            }
            $file = $this->addonPath().'/'.$segments[1].'/src/'.implode('/', array_slice($segments, 2)).'.php';
            if (is_file($file)) {
                require_once $file;
            }
        });
    }

    /** 编译已启用插件清单与监听映射（§6.4 addon:cache），生产免每次启动查库 */
    public function compile(): void
    {
        $this->registerAutoloader();
        $map = [];
        foreach ($this->enabledInfos() as $info) {
            $listeners = [];
            if (class_exists($info->providerClass())) {
                $listeners = (new \ReflectionClass($info->providerClass()))->getDefaultProperties()['listen'] ?? [];
            }
            $map[$info->name] = [
                'name' => $info->name,
                'title' => $info->title,
                'version' => $info->version,
                'dir' => $info->dir,
                'provider' => $info->providerClass(),
                'listeners' => $listeners,
            ];
        }
        file_put_contents($this->compiledFile(), "<?php\n\nreturn ".var_export($map, true).";\n");
    }

    public function flushCompiled(): void
    {
        @unlink($this->compiledFile());
    }
}
```

`server/app/Support/Addon/AddonServiceProvider.php`：

```php
<?php

namespace App\Support\Addon;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * 插件 ServiceProvider 基类：自定位插件目录（反射类文件 → <插件根>/src → <插件根>），
 * boot 时注册 $listen 事件监听并挂载 /api/admin/addon/<key> 路由（auth:admin）。
 * 插件侧只需继承本类，把监听映射写进 $listen。
 */
abstract class AddonServiceProvider extends ServiceProvider
{
    /** @var array<class-string, list<class-string>> 事件类 => 监听器类列表 */
    protected array $listen = [];

    protected AddonInfo $info;

    public function __construct(\Illuminate\Contracts\Foundation\Application $app)
    {
        parent::__construct($app);
        $dir = dirname((new \ReflectionClass(static::class))->getFileName(), 2);
        $this->info = AddonInfo::fromDir($dir);
    }

    public function register(): void
    {
    }

    public function boot(): void
    {
        foreach ($this->listen as $event => $listeners) {
            Event::listen($event, $listeners);
        }
        $this->mountRoutes();
    }

    /** public 供测试与引导层按需重挂（remount 场景） */
    public function mountRoutes(): void
    {
        $file = $this->info->routeFile();
        if (! is_file($file)) {
            return;
        }
        Route::middleware('auth:admin')
            ->prefix('api/admin/addon/'.$this->info->name)
            ->group($file);
    }
}
```

`server/app/Providers/AddonBootServiceProvider.php`：

```php
<?php

namespace App\Providers;

use App\Support\Addon\AddonManager;
use Illuminate\Support\ServiceProvider;

/** 插件系统引导入口（§6.4），在 bootstrap/app.php 以 withProviders 注册 */
class AddonBootServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AddonManager::class);
    }

    public function boot(): void
    {
        $this->app->make(AddonManager::class)->boot();
    }
}
```

`server/bootstrap/app.php` 在 `->withRouting(...)` 之后追加一行：

```php
    ->withProviders([\App\Providers\AddonBootServiceProvider::class])
```

`addons/demo/info.json`：

```json
{
    "name": "demo",
    "title": "演示插件",
    "description": "M2 插件系统验收空插件：一张表、一个接口、一个菜单",
    "version": "0.1.0",
    "type": "app",
    "support_version": "0.1.0",
    "dependencies": []
}
```

`addons/demo/src/Addon.php`：

```php
<?php

namespace Addons\demo;

use App\Support\Addon\Contracts\Lifecycle;

/** M2 验收空插件：生命周期钩子暂无自定义逻辑，保持显式空实现作为插件模板 */
class Addon implements Lifecycle
{
    public function install(): void
    {
    }

    public function uninstall(): void
    {
    }

    public function enable(): void
    {
    }

    public function disable(): void
    {
    }

    public function upgrade(string $fromVersion): void
    {
    }
}
```

`addons/demo/src/AddonServiceProvider.php`：

```php
<?php

namespace Addons\demo;

use App\Support\Addon\AddonServiceProvider as BaseProvider;

class AddonServiceProvider extends BaseProvider
{
    /** 事件监听在 Task 7 接入框架登录埋点时补上 */
    protected array $listen = [];
}
```

`addons/demo/src/Http/Controllers/NoteController.php`：

```php
<?php

namespace Addons\demo\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Http\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NoteController extends Controller
{
    use ApiResponse;

    public function index(): JsonResponse
    {
        return $this->success(['list' => DB::table('demo_notes')->orderByDesc('id')->get()->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['content' => 'required|string|max:500']);
        $id = DB::table('demo_notes')->insertGetId([
            'admin_id' => $request->user('admin')->id,
            'content' => $data['content'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->success(['id' => $id], '已创建');
    }
}
```

`addons/demo/routes/admin.php`（相对路径由基类包 `api/admin/addon/demo` 前缀）：

```php
<?php

use Addons\demo\Http\Controllers\NoteController;
use Illuminate\Support\Facades\Route;

Route::get('notes', [NoteController::class, 'index'])->middleware('permission:addon.demo.note.index');
Route::post('notes', [NoteController::class, 'store'])->middleware('permission:addon.demo.note.store');
```

- [ ] **Step 4: 跑测试确认通过**

Run: `docker compose -f docker/docker-compose.yml exec -u 1000:1000 php sh -c "cd /var/www/server && php artisan test --filter=BootTest"`
Expected: PASS（4 项）

- [ ] **Step 5: 全量回归 + 提交**

```bash
docker compose -f docker/docker-compose.yml exec -u 1000:1000 php sh -c "cd /var/www/server && php artisan test"
git add server/app/Support/Addon server/app/Providers server/bootstrap/app.php server/tests addons/demo
git commit -m "feat(m2): addon bootstrap, lifecycle contract and demo plugin skeleton"
```

---

### Task 4: 生命周期层 — AddonInstaller 与菜单/权限同步（§6.3、§6.5）

**Files:**
- Create: `server/app/Support/Addon/AddonInstaller.php`
- Modify: `server/app/Admin/Services/MenuService.php`（禁用插件菜单不渲染）
- Create: `addons/demo/database/migrations/2026_09_15_000001_create_demo_notes_table.php`
- Create: `addons/demo/database/menus.php`
- Create: `addons/demo/database/permissions.php`
- Test: `server/tests/Feature/Addon/AddonLifecycleTest.php`

**Interfaces:**
- Consumes: `AddonInfo`、`AddonManager`、`Addon` 模型、`Contracts\Lifecycle`、`AddonServiceProvider::mountRoutes()`、Pest 辅助
- Produces: `AddonInstaller`：`install(string $name): Addon`、`uninstall(string $name, bool $keepData = false): void`、`enable(string $name): void`、`disable(string $name): void`（越界操作抛 `AddonException`）；`MenuService::treeFor()` 过滤禁用插件的菜单

**行为约定（源自设计文档）：**
- 安装顺序：校验（已装/support_version/依赖已装）→ 迁移 → 注册表写入（**enabled=true**，状态机"未安装→已启用"）→ 菜单写入（`addon_key` 强制覆盖）→ 权限创建（菜单 permission 串 ∪ `database/permissions.php`，`module=<key>`）→ `Lifecycle::install()` 钩子
- 卸载前置：必须先 disable（状态机"已禁用→卸载"）；顺序：业务表回滚（`--keep-data` 跳过）→ `Lifecycle::uninstall()` → 删菜单（`addon_key=<key>`）→ 删权限（`module=<key>`，含 spatie 两张中间表关联行）→ 删注册行
- 启用校验依赖插件均已启用；禁用/启用均翻 `enabled` 并调用对应钩子
- 禁用不删菜单行，`treeFor` 按 `enabled=false` 插件过滤渲染（§6.3"仅已启用参与菜单渲染"）
- 进程内即时性：install/enable 收尾时若 `app()->booted()` 则立即 `app()->register($providerClass)`（HTTP 测试与同进程生效）；disable/uninstall 同进程摘除事件监听（路由摘除以进程重启为准，测试用 `remount_routes()` 模拟新进程）

- [ ] **Step 1: 写失败测试** — `server/tests/Feature/Addon/AddonLifecycleTest.php`

```php
<?php

use App\Admin\Models\Admin;
use App\Admin\Services\MenuService;
use App\Support\Addon\AddonException;
use App\Support\Addon\AddonInstaller;
use App\Support\Addon\Models\Addon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    (new App\Admin\Seeds\RbacSeeder)->run();
    (new App\Admin\Seeds\MenuSeeder)->run();
});

afterEach(function () {
    app(AddonManager::class)->flushCompiled();
});

it('安装 demo：注册表/业务表/菜单/权限齐备且接口可用', function () {
    $record = app(AddonInstaller::class)->install('demo');
    expect($record->enabled)->toBeTrue()
        ->and(Schema::hasTable('demo_notes'))->toBeTrue();

    $menuNames = Menu::where('addon_key', 'demo')->pluck('name');
    expect($menuNames)->toContain('demo', 'demo.note')
        ->and(Menu::where('name', 'demo.note')->value('view_path'))->toBe('note/index')
        ->and(Permission::where('module', 'demo')->pluck('name'))
        ->toContain('addon.demo.note.index', 'addon.demo.note.store');

    $token = admin_token();
    $this->getJson('/api/admin/addon/demo/notes', ['Authorization' => "Bearer {$token}"])
        ->assertOk()->assertJsonPath('code', 0)->assertJsonPath('data.list', []);
    $this->postJson('/api/admin/addon/demo/notes', ['content' => 'hello'],
        ['Authorization' => "Bearer {$token}"])
        ->assertOk()->assertJsonPath('code', 0);
    expect(DB::table('demo_notes')->count())->toBe(1);
});

it('超管菜单树出现插件菜单，无权限管理员看不到', function () {
    app(AddonInstaller::class)->install('demo');
    expect(menu_tree_names((new MenuService)->treeFor(super_admin())))->toContain('demo', 'demo.note');

    $u = Admin::create(['username' => 'noperm2', 'password' => 'x123456', 'status' => 1]);
    expect(menu_tree_names((new MenuService)->treeFor($u)))->not->toContain('demo');
});

it('无权限管理员调用插件接口返回 403 信封', function () {
    app(AddonInstaller::class)->install('demo');
    $u = Admin::create(['username' => 'plain', 'password' => 'x123456', 'status' => 1]);
    $token = $this->postJson('/api/admin/auth/login', ['username' => 'plain', 'password' => 'x123456'])
        ->json('data.token');
    $this->getJson('/api/admin/addon/demo/notes', ['Authorization' => "Bearer {$token}"])
        ->assertOk()->assertJsonPath('code', 403);
});

it('disable：菜单不渲染但行保留、路由 404、业务表保留', function () {
    app(AddonInstaller::class)->install('demo');
    app(AddonInstaller::class)->disable('demo');
    expect(Addon::find('demo')->enabled)->toBeFalse()
        ->and(Menu::where('addon_key', 'demo')->count())->toBe(2)
        ->and(menu_tree_names((new MenuService)->treeFor(super_admin())))->not->toContain('demo')
        ->and(Schema::hasTable('demo_notes'))->toBeTrue();

    remount_routes();
    $token = admin_token();
    $this->getJson('/api/admin/addon/demo/notes', ['Authorization' => "Bearer {$token}"])
        ->assertOk()->assertJsonPath('code', 404);
});

it('enable：路由与菜单完整恢复', function () {
    $installer = app(AddonInstaller::class);
    $installer->install('demo');
    $installer->disable('demo');
    $installer->enable('demo');
    expect(menu_tree_names((new MenuService)->treeFor(super_admin())))->toContain('demo');

    remount_routes();
    $token = admin_token();
    $this->getJson('/api/admin/addon/demo/notes', ['Authorization' => "Bearer {$token}"])
        ->assertOk()->assertJsonPath('code', 0);
});

it('状态机：启用时禁止卸载/重复启用，禁用时禁止再禁用', function () {
    $installer = app(AddonInstaller::class);
    $installer->install('demo');
    expect(fn () => $installer->uninstall('demo'))->toThrow(AddonException::class)
        ->and(fn () => $installer->enable('demo'))->toThrow(AddonException::class);
    $installer->disable('demo');
    expect(fn () => $installer->disable('demo'))->toThrow(AddonException::class);
});

it('卸载：注册行/菜单/权限清除且业务表回滚', function () {
    $installer = app(AddonInstaller::class);
    $installer->install('demo');
    $installer->disable('demo');
    $installer->uninstall('demo');
    expect(Addon::find('demo'))->toBeNull()
        ->and(Menu::where('addon_key', 'demo')->count())->toBe(0)
        ->and(Permission::where('module', 'demo')->count())->toBe(0)
        ->and(Schema::hasTable('demo_notes'))->toBeFalse();
});

it('--keep-data 卸载保留业务表', function () {
    $installer = app(AddonInstaller::class);
    $installer->install('demo');
    $token = admin_token();
    $this->postJson('/api/admin/addon/demo/notes', ['content' => '保留我'],
        ['Authorization' => "Bearer {$token}"])->assertOk();
    $installer->disable('demo');
    $installer->uninstall('demo', keepData: true);
    expect(Schema::hasTable('demo_notes'))->toBeTrue()
        ->and(DB::table('demo_notes')->where('content', '保留我')->exists())->toBeTrue()
        ->and(Addon::find('demo'))->toBeNull()
        ->and(Menu::where('addon_key', 'demo')->count())->toBe(0);
});

it('卸载后可重新安装', function () {
    $installer = app(AddonInstaller::class);
    $installer->install('demo');
    $installer->disable('demo');
    $installer->uninstall('demo');
    $installer->install('demo');
    expect(Addon::find('demo')->enabled)->toBeTrue()
        ->and(Schema::hasTable('demo_notes'))->toBeTrue()
        ->and(Menu::where('addon_key', 'demo')->count())->toBe(2);
});
```

（文件顶部需 `use App\Support\Addon\AddonManager;`。）

- [ ] **Step 2: 跑测试确认失败**

Run: `docker compose -f docker/docker-compose.yml exec -u 1000:1000 php sh -c "cd /var/www/server && php artisan test --filter=AddonLifecycleTest"`
Expected: FAIL（`AddonInstaller` 类不存在）

- [ ] **Step 3: 实现 `server/app/Support/Addon/AddonInstaller.php`**

```php
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

    /** 禁用/卸载后摘除本进程已挂的事件监听（超管菜单树/权限缓存不依赖进程内状态） */
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
        if (app()->booted() && class_exists($info->providerClass())) {
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
```

`server/app/Admin/Services/MenuService.php` 的 `treeFor` 修改为（新增禁用插件过滤一行 + use）：

```php
use App\Support\Addon\Models\Addon;
```

```php
        $perms = null;
        if (!$admin->hasRole('super_admin')) {
            $perms = $admin->getAllPermissions()->pluck('name')->flip();
        }

        // 禁用插件保留菜单行但不参与渲染（§6.3：仅已启用插件参与菜单渲染）
        $disabledAddons = Addon::query()->where('enabled', false)->pluck('name')->flip();

        $visible = Menu::orderBy('sort')->get()
            ->filter(fn (Menu $m) => $m->is_show)
            ->filter(fn (Menu $m) => $m->addon_key === '' || !$disabledAddons->has($m->addon_key))
            // permission 为空串（或防御性地为 null）表示无权限要求；in_array 严格比较避免 '0' 被 PHP 判空
            ->filter(fn (Menu $m) => $perms === null || in_array($m->permission, ['', null], true) || $perms->has($m->permission))
            ->values();
```

`addons/demo/database/migrations/2026_09_15_000001_create_demo_notes_table.php`（匿名类，插件迁移不进 composer classmap）：

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_notes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('admin_id');
            $table->text('content');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_notes');
    }
};
```

`addons/demo/database/menus.php`：

```php
<?php

/*
 * 插件菜单定义（§6.5）：写入时 addon_key 强制为插件名。
 * view_path 相对插件前端根（M3 复制到 admin/src/addons/demo/ 后生效）。
 */
return [
    [
        'name' => 'demo', 'title' => '演示插件', 'icon' => 'Box',
        'route_path' => '/demo', 'sort' => 200,
        'children' => [
            [
                'name' => 'demo.note', 'title' => '便签', 'icon' => 'Document',
                'route_path' => '/demo/note', 'view_path' => 'note/index',
                'permission' => 'addon.demo.note.index', 'sort' => 1,
            ],
        ],
    ],
];
```

`addons/demo/database/permissions.php`：

```php
<?php

/* 插件全部权限串：菜单绑定的 + 仅接口用的（§5.3 命名约定 addon.<key>.<controller>.<action>） */
return [
    'addon.demo.note.index',
    'addon.demo.note.store',
];
```

- [ ] **Step 4: 跑测试确认通过**

Run: `docker compose -f docker/docker-compose.yml exec -u 1000:1000 php sh -c "cd /var/www/server && php artisan test --filter=AddonLifecycleTest"`
Expected: PASS（9 项）

- [ ] **Step 5: 全量回归 + 提交**

```bash
docker compose -f docker/docker-compose.yml exec -u 1000:1000 php sh -c "cd /var/www/server && php artisan test"
git add server/app/Support/Addon server/app/Admin/Services/MenuService.php server/tests addons/demo
git commit -m "feat(m2): addon lifecycle orchestration with menu/permission sync"
```

---

### Task 5: CLI 层 — 七个 artisan 命令

**Files:**
- Create: `server/app/Support/Addon/Console/AddonListCommand.php`、`AddonInstallCommand.php`、`AddonUninstallCommand.php`、`AddonEnableCommand.php`、`AddonDisableCommand.php`、`AddonCacheCommand.php`、`AddonClearCommand.php`
- Modify: `server/bootstrap/app.php`（`->withCommands([...])`）
- Test: `server/tests/Feature/Addon/AddonCommandTest.php`

**Interfaces:**
- Consumes: `AddonManager`（scan/compile/flushCompiled）、`AddonInstaller`（四个生命周期操作）、`AddonException`
- Produces: `addon:list`、`addon:install {name}`、`addon:uninstall {name} {--keep-data}`、`addon:enable {name}`、`addon:disable {name}`、`addon:cache`、`addon:clear`；全部失败路径 exit `FAILURE`(1)，错误走 `$this->error()`

- [ ] **Step 1: 写失败测试** — `server/tests/Feature/Addon/AddonCommandTest.php`

```php
<?php

use App\Support\Addon\AddonManager;
use App\Support\Addon\AddonInstaller;
use App\Support\Addon\Models\Addon;

beforeEach(function () {
    (new App\Admin\Seeds\RbacSeeder)->run();
    (new App\Admin\Seeds\MenuSeeder)->run();
});

afterEach(function () {
    app(AddonManager::class)->flushCompiled();
    remove_dir(storage_path('framework/addon-fixture'));
});

it('addon:list 列出磁盘插件（含未安装）', function () {
    $this->artisan('addon:list')->assertExitCode(0);
});

it('安装不存在的插件失败', function () {
    $this->artisan('addon:install', ['name' => 'ghost'])->assertExitCode(1);
});

it('重复安装失败', function () {
    install_demo();
    $this->artisan('addon:install', ['name' => 'demo'])->assertExitCode(1);
});

it('未启用时执行 disable 失败', function () {
    install_demo();
    app(AddonInstaller::class)->disable('demo');
    $this->artisan('addon:disable', ['name' => 'demo'])->assertExitCode(1);
});

it('已启用时执行 enable 失败', function () {
    install_demo();
    $this->artisan('addon:enable', ['name' => 'demo'])->assertExitCode(1);
});

it('启用状态执行 uninstall 失败', function () {
    install_demo();
    $this->artisan('addon:uninstall', ['name' => 'demo'])->assertExitCode(1)
        ->and(Addon::find('demo'))->not->toBeNull();
});

it('support_version 不满足拒绝安装且零副作用', function () {
    make_addon_dir('needhigh', ['support_version' => '99.0.0']);
    config(['arkadmin.addon_path' => storage_path('framework/addon-fixture')]);
    $this->artisan('addon:install', ['name' => 'needhigh'])->assertExitCode(1)
        ->and(Addon::find('needhigh'))->toBeNull();
});

it('依赖未安装拒绝安装', function () {
    make_addon_dir('needdep', ['dependencies' => ['ghost']]);
    config(['arkadmin.addon_path' => storage_path('framework/addon-fixture')]);
    $this->artisan('addon:install', ['name' => 'needdep'])->assertExitCode(1)
        ->and(Addon::find('needdep'))->toBeNull();
});

it('命令走通的完整装/停/卸回路', function () {
    $this->artisan('addon:install', ['name' => 'demo'])->assertExitCode(0)
        ->and(Addon::find('demo')->enabled)->toBeTrue();
    $this->artisan('addon:disable', ['name' => 'demo'])->assertExitCode(0);
    $this->artisan('addon:uninstall', ['name' => 'demo'])->assertExitCode(0)
        ->and(Addon::find('demo'))->toBeNull();
});
```

- [ ] **Step 2: 跑测试确认失败**

Run: `docker compose -f docker/docker-compose.yml exec -u 1000:1000 php sh -c "cd /var/www/server && php artisan test --filter=AddonCommandTest"`
Expected: FAIL（命令不存在，`CommandNotFoundException`）

- [ ] **Step 3: 实现七个命令**

`AddonListCommand.php`：

```php
<?php

namespace App\Support\Addon\Console;

use App\Support\Addon\AddonManager;
use App\Support\Addon\Models\Addon;
use Illuminate\Console\Command;

class AddonListCommand extends Command
{
    protected $signature = 'addon:list';

    protected $description = '列出磁盘与注册表中的插件';

    public function handle(AddonManager $manager): int
    {
        $scan = $manager->scan();
        $rows = [];
        foreach ($scan as $info) {
            $record = Addon::find($info->name);
            $rows[] = [
                $info->name, $info->title, $info->version, $info->supportVersion,
                $record ? '是' : '否', $record?->enabled ? '是' : '否',
            ];
        }
        // 注册表里有、磁盘上没有的（异常态，提示排查）
        foreach (Addon::whereNotIn('name', array_keys($scan))->get() as $record) {
            $rows[] = [$record->name, $record->title, $record->version, '-', '磁盘缺失', $record->enabled ? '是' : '否'];
        }
        $this->table(['name', 'title', 'version', 'support', '已安装', '已启用'], $rows);

        return self::SUCCESS;
    }
}
```

`AddonInstallCommand.php`：

```php
<?php

namespace App\Support\Addon\Console;

use App\Support\Addon\AddonException;
use App\Support\Addon\AddonInstaller;
use Illuminate\Console\Command;

class AddonInstallCommand extends Command
{
    protected $signature = 'addon:install {name : 插件名（addons/ 目录名）}';

    protected $description = '安装插件：迁移 → 注册表 → 菜单/权限（§6.3）';

    public function handle(AddonInstaller $installer): int
    {
        $name = $this->argument('name');
        try {
            $installer->install($name);
        } catch (AddonException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info("插件 [{$name}] 安装成功：迁移已执行、菜单与权限已写入、插件已启用");

        return self::SUCCESS;
    }
}
```

`AddonUninstallCommand.php`：

```php
<?php

namespace App\Support\Addon\Console;

use App\Support\Addon\AddonException;
use App\Support\Addon\AddonInstaller;
use Illuminate\Console\Command;

class AddonUninstallCommand extends Command
{
    protected $signature = 'addon:uninstall {name : 插件名} {--keep-data : 保留插件业务表数据}';

    protected $description = '卸载插件：清菜单/权限/注册行，默认回滚业务表';

    public function handle(AddonInstaller $installer): int
    {
        $name = $this->argument('name');
        try {
            $installer->uninstall($name, (bool) $this->option('keep-data'));
        } catch (AddonException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info("插件 [{$name}] 已卸载".($this->option('keep-data') ? '（业务表已保留）' : '，业务表已回滚'));

        return self::SUCCESS;
    }
}
```

`AddonEnableCommand.php`：

```php
<?php

namespace App\Support\Addon\Console;

use App\Support\Addon\AddonException;
use App\Support\Addon\AddonInstaller;
use Illuminate\Console\Command;

class AddonEnableCommand extends Command
{
    protected $signature = 'addon:enable {name : 插件名}';

    protected $description = '启用已安装的插件';

    public function handle(AddonInstaller $installer): int
    {
        $name = $this->argument('name');
        try {
            $installer->enable($name);
        } catch (AddonException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info("插件 [{$name}] 已启用");

        return self::SUCCESS;
    }
}
```

`AddonDisableCommand.php`：

```php
<?php

namespace App\Support\Addon\Console;

use App\Support\Addon\AddonException;
use App\Support\Addon\AddonInstaller;
use Illuminate\Console\Command;

class AddonDisableCommand extends Command
{
    protected $signature = 'addon:disable {name : 插件名}';

    protected $description = '禁用插件（保留数据与代码，不加载路由/事件/菜单）';

    public function handle(AddonInstaller $installer): int
    {
        $name = $this->argument('name');
        try {
            $installer->disable($name);
        } catch (AddonException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info("插件 [{$name}] 已禁用");

        return self::SUCCESS;
    }
}
```

`AddonCacheCommand.php`：

```php
<?php

namespace App\Support\Addon\Console;

use App\Support\Addon\AddonManager;
use Illuminate\Console\Command;

class AddonCacheCommand extends Command
{
    protected $signature = 'addon:cache';

    protected $description = '编译已启用插件清单与监听映射缓存（§6.4）';

    public function handle(AddonManager $manager): int
    {
        $manager->compile();
        $this->info('插件缓存已生成：'.$manager->compiledFile());

        return self::SUCCESS;
    }
}
```

`AddonClearCommand.php`：

```php
<?php

namespace App\Support\Addon\Console;

use App\Support\Addon\AddonManager;
use Illuminate\Console\Command;

class AddonClearCommand extends Command
{
    protected $signature = 'addon:clear';

    protected $description = '清除插件编译缓存';

    public function handle(AddonManager $manager): int
    {
        $manager->flushCompiled();
        $this->info('插件缓存已清除');

        return self::SUCCESS;
    }
}
```

`server/bootstrap/app.php` 的 `->withProviders([...])` 之后追加：

```php
    ->withCommands([
        \App\Support\Addon\Console\AddonListCommand::class,
        \App\Support\Addon\Console\AddonInstallCommand::class,
        \App\Support\Addon\Console\AddonUninstallCommand::class,
        \App\Support\Addon\Console\AddonEnableCommand::class,
        \App\Support\Addon\Console\AddonDisableCommand::class,
        \App\Support\Addon\Console\AddonCacheCommand::class,
        \App\Support\Addon\Console\AddonClearCommand::class,
    ])
```

- [ ] **Step 4: 跑测试确认通过**

Run: `docker compose -f docker/docker-compose.yml exec -u 1000:1000 php sh -c "cd /var/www/server && php artisan test --filter=AddonCommandTest"`
Expected: PASS（9 项）

- [ ] **Step 5: 全量回归 + 提交**

```bash
docker compose -f docker/docker-compose.yml exec -u 1000:1000 php sh -c "cd /var/www/server && php artisan test"
git add server/app/Support/Addon server/bootstrap/app.php server/tests
git commit -m "feat(m2): artisan addon commands (list/install/uninstall/enable/disable/cache/clear)"
```

---

### Task 6: 缓存层 — addon:cache 编译缓存与引导（§6.4 后半）

**Files:**
- Modify: 无新生产代码（`AddonManager::compile/flushCompiled/loadableInfos` 与两个缓存命令均已在 T3/T5 落地）
- Test: `server/tests/Feature/Addon/AddonCacheTest.php`

**Interfaces:**
- Consumes: `AddonManager::compile/flushCompiled/isCompiled/compiledFile`、`addon:cache`/`addon:clear` 命令
- Produces: 缓存文件 `bootstrap/cache/addons.php`（`[name => ['name','title','version','dir','provider','listeners']]`，仅含已启用插件）；boot 时缓存优先于注册表现算

- [ ] **Step 1: 写失败测试** — `server/tests/Feature/Addon/AddonCacheTest.php`

```php
<?php

use App\Support\Addon\AddonManager;

beforeEach(function () {
    (new App\Admin\Seeds\RbacSeeder)->run();
    (new App\Admin\Seeds\MenuSeeder)->run();
});

afterEach(function () {
    // 测试进程写出的编译缓存必须清掉，避免污染同机开发运行时
    app(AddonManager::class)->flushCompiled();
});

it('addon:cache 生成含 provider 与监听映射的编译缓存', function () {
    install_demo();
    $manager = app(AddonManager::class);
    expect($manager->isCompiled())->toBeFalse();
    $this->artisan('addon:cache')->assertExitCode(0);
    expect($manager->isCompiled())->toBeTrue();

    $map = require $manager->compiledFile();
    expect($map['demo']['provider'])->toBe('Addons\demo\AddonServiceProvider')
        ->and($map['demo']['dir'])->toBe(base_path('addons/demo'))
        ->and($map['demo']['listeners'])->toBeArray();
});

it('任一生命周期变更都会冲掉编译缓存（下次 boot 走注册表现算）', function () {
    install_demo();
    $manager = app(AddonManager::class);
    $manager->compile();
    expect($manager->isCompiled())->toBeTrue();
    app(App\Support\Addon\AddonInstaller::class)->disable('demo');
    expect($manager->isCompiled())->toBeFalse();
});

it('addon:clear 清除缓存', function () {
    install_demo();
    $manager = app(AddonManager::class);
    $manager->compile();
    $this->artisan('addon:clear')->assertExitCode(0);
    expect($manager->isCompiled())->toBeFalse();
});

it('缓存指向的插件目录失效时 boot 降级为跳过且不致命', function () {
    install_demo();
    $manager = app(AddonManager::class);
    $manager->compile();
    // 模拟缓存过期（记录的 dir 不存在）
    file_put_contents($manager->compiledFile(), "<?php\n\nreturn ".var_export([
        'ghost' => ['name' => 'ghost', 'dir' => '/nonexistent/addons/ghost'],
    ], true).";\n");
    // boot 不抛异常（loadableInfos 对坏目录记日志跳过）
    $manager->boot();
    expect(true)->toBeTrue();
});
```

- [ ] **Step 2: 跑测试确认通过**（实现已在 T3/T5 完成，本任务是行为锁定测试；若失败先修 T3/T5 实现再回到全绿）

Run: `docker compose -f docker/docker-compose.yml exec -u 1000:1000 php sh -c "cd /var/www/server && php artisan test --filter=AddonCacheTest"`
Expected: PASS（4 项）

- [ ] **Step 3: 全量回归 + 提交**

```bash
docker compose -f docker/docker-compose.yml exec -u 1000:1000 php sh -c "cd /var/www/server && php artisan test"
git add server/tests
git commit -m "test(m2): lock addon compiled cache behavior"
```

---

### Task 7: 事件埋点 — admin.login.successed 与插件监听（§5.5）

**Files:**
- Create: `server/app/Admin/Events/AdminLoginSuccessed.php`
- Modify: `server/app/Admin/Http/Controllers/AuthController.php`（login 成功后 dispatch）
- Modify: `addons/demo/src/AddonServiceProvider.php`（补 `$listen`）
- Create: `addons/demo/src/Listeners/RecordAdminLogin.php`
- Modify: `server/app/Support/Addon/AddonInstaller.php`（`disable()` 增加 `detachListeners($info)` 调用——若 T4 已含则确认即可）
- Test: `server/tests/Feature/Addon/AddonEventTest.php`

**Interfaces:**
- Consumes: T4 安装器 `finish()` 的进程内即时注册（安装后监听立即生效）、`detachListeners`
- Produces: `App\Admin\Events\AdminLoginSuccessed`（`public Admin $admin`）——框架公开埋点，事件清单自本事件起在框架文档维护

- [ ] **Step 1: 写失败测试** — `server/tests/Feature/Addon/AddonEventTest.php`

```php
<?php

use App\Support\Addon\AddonInstaller;
use App\Support\Addon\AddonManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    (new App\Admin\Seeds\RbacSeeder)->run();
    (new App\Admin\Seeds\MenuSeeder)->run();
});

afterEach(function () {
    app(AddonManager::class)->flushCompiled();
});

it('登录成功触发 admin.login.successed 且插件监听写入业务表', function () {
    install_demo(); // 安装收尾即注册 provider，监听在本进程立即生效
    admin_token();  // 登录 → 事件 → demo 监听器写 demo_notes
    expect(DB::table('demo_notes')->where('content', 'login:admin')->count())->toBe(1);
});

it('卸载后登录不再写入且不报错', function () {
    $installer = app(AddonInstaller::class);
    $installer->install('demo');
    $installer->disable('demo'); // 进程内摘除监听
    $installer->uninstall('demo'); // 业务表回滚
    admin_token(); // 表已不存在，监听已摘除 → 登录必须正常
    expect(Schema::hasTable('demo_notes'))->toBeFalse();
});
```

- [ ] **Step 2: 跑测试确认失败**

Run: `docker compose -f docker/docker-compose.yml exec -u 1000:1000 php sh -c "cd /var/www/server && php artisan test --filter=AddonEventTest"`
Expected: FAIL（事件类不存在，第一个用例 demo_notes 无行）

- [ ] **Step 3: 实现**

`server/app/Admin/Events/AdminLoginSuccessed.php`：

```php
<?php

namespace App\Admin\Events;

use App\Admin\Models\Admin;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** §5.5 框架公开埋点：管理员登录成功。插件在 provider 的 $listen 中监听本事件 */
class AdminLoginSuccessed
{
    use Dispatchable, SerializesModels;

    public function __construct(public Admin $admin)
    {
    }
}
```

`server/app/Admin/Http/Controllers/AuthController.php`：顶部 use 区加 `use App\Admin\Events\AdminLoginSuccessed;`，`login()` 中 `$token = ...` 行之后插入：

```php
        // §5.5 埋点：登录成功。插件可监听（框架公开接口，删除视为破坏性变更）
        event(new AdminLoginSuccessed($admin));
```

`addons/demo/src/Listeners/RecordAdminLogin.php`：

```php
<?php

namespace Addons\demo\Listeners;

use App\Admin\Events\AdminLoginSuccessed;
use Illuminate\Support\Facades\DB;

class RecordAdminLogin
{
    /** 登录成功写一条便签：插件监听框架事件的端到端证明 */
    public function handle(AdminLoginSuccessed $event): void
    {
        DB::table('demo_notes')->insert([
            'admin_id' => $event->admin->id,
            'content' => 'login:'.$event->admin->username,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
```

`addons/demo/src/AddonServiceProvider.php` 的 `$listen` 替换为：

```php
    protected array $listen = [
        \App\Admin\Events\AdminLoginSuccessed::class => [
            \Addons\demo\Listeners\RecordAdminLogin::class,
        ],
    ];
```

（`AddonInstaller::disable()` 已在 T4 实现中包含 `detachListeners`，若缺失则按 T4 代码补上。）

- [ ] **Step 4: 跑测试确认通过 + 全量回归**

Run: `docker compose -f docker/docker-compose.yml exec -u 1000:1000 php sh -c "cd /var/www/server && php artisan test"`
Expected: 全部 PASS（含既有 44 项）

- [ ] **Step 5: 提交**

```bash
git add server/app addons/demo server/tests
git commit -m "feat(m2): admin.login.successed hook with addon listener lifecycle"
```

---

### Task 8: 文档、手工验收（含三缓存矩阵）与收尾

**Files:**
- Modify: `AGENTS.md`（常用命令补 addon CLI）
- Modify: `docs/superpowers/specs/2026-09-14-arkadmin-design.md`（§6.4 勘误 + §6.1 命名空间说明 + M2 状态）
- Create: `docs/superpowers/plans/2026-09-15-arkadmin-m2-wrapup.md`（验收记录，模板见下）

- [ ] **Step 1: 全量测试最终确认**

```bash
docker compose -f docker/docker-compose.yml exec -u 1000:1000 php sh -c "cd /var/www/server && php artisan test"
```
Expected: 全部 PASS（M1 既有 44 + M2 新增约 37）

- [ ] **Step 2: 手工验收 — 空插件装/停/卸（M2 完成标志）**

在开发库（`arkadmin`）执行，`D` 为容器命令前缀：

```bash
D="docker compose -f docker/docker-compose.yml exec -u 1000:1000 php sh -c"
```

1. `$D "cd /var/www/server && php artisan addon:list"` → demo 行显示"已安装=否"
2. `$D "cd /var/www/server && php artisan addon:install demo"` → 成功提示
3. 验证接口与菜单：curl 登录取 token，`GET /api/admin/addon/demo/notes` → code 0；`POST /api/admin/auth/login` 后 `/api/admin/auth/me` 的 menus 含"演示插件"
4. `$D "cd /var/www/server && php artisan addon:disable demo"` → notes 接口 404 信封、/auth/me menus 无演示插件；`SELECT count(*) FROM menus WHERE addon_key='demo'` 仍为 2
5. `$D "cd /var/www/server && php artisan addon:enable demo"` → 完整恢复
6. `$D "cd /var/www/server && php artisan addon:uninstall demo"` → addons/menus/permissions 清空、`demo_notes` 表不存在

- [ ] **Step 3: 手工验收 — 三缓存矩阵（§11 风险对策）**

```bash
$D "cd /var/www/server && php artisan config:cache && php artisan event:cache && php artisan route:cache"
```

缓存全开状态下重复装/停/卸各一遍，每步验证接口可达性变化：

1. `addon:install demo` → notes 接口 code 0（命令自动重建三项缓存）
2. `addon:disable demo` → notes 接口 404
3. `addon:enable demo` → code 0
4. `addon:disable demo && addon:uninstall demo` → 404 + 清理干净
5. `$D "cd /var/www/server && php artisan optimize:clear"` 恢复无缓存状态

再验证 `addon:cache`：install demo → `addon:cache` → `docker compose -f docker/docker-compose.yml restart php` → 登录 + notes 接口正常（从编译缓存引导）→ 收尾 uninstall + `addon:clear`。

- [ ] **Step 4: 更新 AGENTS.md 常用命令**

在「常用命令」一节追加：

```markdown
- 插件 CLI：`docker compose -f docker/docker-compose.yml exec -u 1000:1000 php sh -c "cd /var/www/server && php artisan addon:list"`（另有 addon:install / addon:uninstall --keep-data / addon:enable / addon:disable / addon:cache / addon:clear）
```

- [ ] **Step 5: 更新设计文档**

1. §6.4 第 3 点改为："对 enabled 插件：注册其 `AddonServiceProvider`（框架引导时注册 `Addons\` 前缀 PSR-4 自动加载器，映射 `Addons\<key>\` → `addons/<key>/src/`，插件作者无需任何 composer 配置）"
2. §6.1 `src/` 注释改为 `# PHP（命名空间 Addons\<key>\，全小写与目录名一致）`
3. §10 M2 行的完成状态在计划 wrap-up 文档中记录（设计文档本身不改里程碑表）

- [ ] **Step 6: 写验收记录并提交**

`docs/superpowers/plans/2026-09-15-arkadmin-m2-wrapup.md` 记录：全量测试数字、手工验收 1-6 步与缓存矩阵的实测输出摘要、遗留事项（如 M3 前端同步起点）。然后：

```bash
git add AGENTS.md docs
git commit -m "docs(m2): addon core acceptance records and design errata"
git log --oneline
```

---

## 范围外（显式排除，防 scope creep）

- **§6.6 前端同步**（复制/删除 `admin/src/addons/`、构建提示、i18n 合并、组件缺失兜底完善）→ M3
- 插件管理后台页面（`/api/admin/addons` HTTP API + Element 管理界面）→ 未在任何里程碑指派，作为 backlog 候选记入 wrap-up
- `addon:upgrade` CLI（`Lifecycle::upgrade` 接口已就位）→ 需要"版本升级"场景时再加
- 依赖树解析、插件间依赖传递卸载 → 设计文档明确 v1 不做

## Self-Review 记录

- **Spec 覆盖**：§5.4 addons 表 → T1；§6.1 目录结构 → T3/T4 demo 插件（info.json/src/routes/database 全要素）；§6.2 清单与 support_version → T2/T5；§6.3 生命周期与状态机、CLI → T4/T5（upgrade 接口就位、命令按设计清单）；§6.4 引导与缓存 → T3/T6；§6.5 菜单注入与清除（含 spatie 权限删除）→ T4；§5.5 事件 → T7；§10 M2 完成标志 → T8 手工验收；§11 三缓存矩阵 → T8 Step 3。§6.6 前端同步明确排除（M3）。
- **占位符扫描**：全部步骤含实际代码/命令；demo AddonServiceProvider 的 `$listen` 在 T3 先空数组、T7 给出替换代码（显式两步，非占位）；无 TBD。
- **类型一致性**：`AddonInfo` 属性名 `supportVersion`（VO 内）与 JSON 键 `support_version`（外部）区分一致；`AddonInstaller::install(string): Addon` / `uninstall(string, bool): void` / `enable/disable(string): void` 与 T5 命令调用一致；`AddonManager::enabledInfos(): array<string, AddonInfo>` 与 Pest 辅助 `remount_routes()` 使用一致；`mountRoutes()` public 与 `remount_routes()` 调用一致；事件类名 `AdminLoginSuccessed` 在控制器/监听器/缓存映射三处一致。
