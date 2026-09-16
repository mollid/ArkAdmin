<?php

use App\Admin\Models\Menu;
use App\Admin\Services\MenuService;
use App\Support\Addon\AddonInstaller;
use App\Support\Addon\AddonManager;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    (new App\Admin\Seeds\RbacSeeder)->run();
    (new App\Admin\Seeds\MenuSeeder)->run();
});

afterEach(function () {
    app(AddonManager::class)->flushCompiled();
});

it('安装 cms：表/菜单/权限/种子数据齐备', function () {
    app(AddonInstaller::class)->install('cms');

    expect(Schema::hasTable('cms_categories'))->toBeTrue()
        ->and(Schema::hasTable('cms_articles'))->toBeTrue()
        // §6.3 install 钩子种子：默认栏目 + 示例文章
        ->and(\Addons\cms\Models\Category::count())->toBe(1)
        ->and(\Addons\cms\Models\Article::count())->toBe(1)
        ->and(Menu::where('addon_key', 'cms')->count())->toBe(3)
        ->and(Permission::where('module', 'cms')->count())->toBe(8)
        // RbacSeeder 语义延续：插件新权限自动授予超管
        ->and(\Spatie\Permission\Models\Role::where('name', 'super_admin')
            ->where('guard_name', 'admin')->first()->hasPermissionTo('addon.cms.article.store'))->toBeTrue()
        // 菜单树出现 CMS 三个节点
        ->and(menu_tree_names((new MenuService)->treeFor(super_admin())))
        ->toContain('cms', 'cms.category', 'cms.article');
});
