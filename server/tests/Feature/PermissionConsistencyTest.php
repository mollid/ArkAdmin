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
