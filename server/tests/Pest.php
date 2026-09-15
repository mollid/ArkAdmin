<?php

uses(Tests\TestCase::class)->in('Feature', 'Unit');

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

/** 安装仓库内置 demo 插件（M2 验收插件，位于 addons/demo） */
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
    static $seq = 0;
    $dir = storage_path('framework/addon-fixture/'.($seq++).'_'.uniqid($name.'_', true));
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
