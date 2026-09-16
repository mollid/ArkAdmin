<?php

use App\Admin\Models\Admin;
use App\Support\Addon\AddonInstaller;
use App\Support\Addon\AddonManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit');

// —— M2 插件系统测试公共辅助 ——

/** 登录超管拿 Bearer token */
function admin_token(): string
{
    return test()->postJson('/api/admin/auth/login', ['username' => 'admin', 'password' => '123456'])
        ->assertOk()->json('data.token');
}

function super_admin(): Admin
{
    return Admin::where('username', 'admin')->firstOrFail();
}

/** 安装仓库内置 demo 插件（M2 验收插件，位于 addons/demo） */
function install_demo(): void
{
    app(AddonInstaller::class)->install('demo');
}

/** 重建路由表：模拟新进程启动时的真实挂载结果（框架 api 路由 + 已启用插件路由） */
function remount_routes(): void
{
    app('router')->setRoutes(new RouteCollection);
    Route::middleware('api')->prefix('api')->group(base_path('routes/api.php'));
    foreach (app(AddonManager::class)->enabledInfos() as $info) {
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

/** 在测试固定目录下生成插件 fixture（合法最小 info.json + 可选 src/）。目录名强制等于插件名 */
function make_addon_dir(string $name, array $infoOverrides = [], bool $withSrc = true): string
{
    $dir = storage_path('framework/addon-fixture/'.$name);
    remove_dir($dir);
    mkdir($dir.($withSrc ? '/src' : ''), 0777, true);
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
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($dir);
}

// —— M5 ark:crud 生成器测试辅助 ——

/** 生成器 fixture：最小插件脚手架（menus 带通用标记对，供条目插入） */
function make_crud_addon(string $name, bool $withMenuMarkers = true): string
{
    $dir = make_addon_dir($name);
    @mkdir($dir.'/database', 0777, true);
    @mkdir($dir.'/routes', 0777, true);
    @mkdir($dir.'/admin/lang', 0777, true);

    file_put_contents($dir.'/routes/admin.php', "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n");
    file_put_contents($dir.'/database/permissions.php', "<?php\n\nreturn [\n];\n");
    $menuMarkers = $withMenuMarkers ? "            // ark:crud:menus:start\n            // ark:crud:menus:end\n" : '';
    file_put_contents($dir.'/database/menus.php', "<?php\n\nreturn [\n    [\n"
        ."        'name' => '{$name}', 'title' => 'FX', 'icon' => 'Box', 'route_path' => '/{$name}', 'sort' => 300,\n"
        ."        'children' => [\n{$menuMarkers}        ],\n    ],\n];\n");
    file_put_contents($dir.'/admin/lang/zh-cn.ts', "export default {\n};\n");

    return $dir;
}

/** 给 fixture 补上可安装的最小 Provider（Addons\ 前缀自动加载器按约定路径解析） */
function make_fixture_installable(string $dir, string $name): void
{
    @mkdir($dir.'/src/Http/Controllers', 0777, true);
    file_put_contents($dir.'/src/AddonServiceProvider.php', <<<PHP
    <?php

    namespace Addons\\{$name};

    use App\\Support\\Addon\\AddonServiceProvider as BaseProvider;

    class AddonServiceProvider extends BaseProvider
    {
        protected array \$listen = [];
    }

    PHP);
    file_put_contents($dir.'/src/Addon.php', <<<PHP
    <?php

    namespace Addons\\{$name};

    use App\\Support\\Addon\\Contracts\\Lifecycle;

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

        public function upgrade(string \$fromVersion): void
        {
        }
    }

    PHP);
}

function create_items_table(): void
{
    Schema::dropIfExists('fx_items');
    Schema::create('fx_items', function (Blueprint $t) {
        $t->id();
        $t->string('title', 100)->comment('标题');
        $t->text('content')->nullable();
        $t->integer('views')->default(0);
        $t->boolean('is_top')->default(false);
        $t->timestamp('published_at')->nullable();
        $t->timestamps();
    });
}

// 插件前端产物的隔离在 Tests\TestCase::setUp/tearDown（Pest.php 顶层 afterEach 不生效：
// Pest 按定义文件为键索引钩子，全局清理必须挂在 TestCase 上）
