<?php

use App\Admin\Models\Menu;
use App\Support\Addon\AddonException;
use App\Support\Addon\AddonInstaller;
use App\Support\Addon\AddonManager;
use App\Support\Addon\Models\Addon;

beforeEach(function () {
    (new App\Admin\Seeds\RbacSeeder)->run();
    (new App\Admin\Seeds\MenuSeeder)->run();
    config(['arkadmin.addon_path' => storage_path('framework/addon-fixture')]);
});

afterEach(function () {
    app(AddonManager::class)->flushCompiled();
    remove_dir(storage_path('framework/addon-fixture'));
});

function make_addon_with_menus(string $name, array $menus): string
{
    $dir = make_addon_dir($name);
    mkdir($dir.'/database', 0777, true);
    file_put_contents($dir.'/database/menus.php', "<?php\n\nreturn ".var_export($menus, true).";\n");

    return $dir;
}

it('菜单定义非法时安装失败且不留半安装状态（注册表回滚）', function () {
    // 节点缺 title → syncMenus 抛异常（发生在注册表写入之后）
    make_addon_with_menus('badmenu', [['name' => 'badmenu.x']]);

    expect(fn () => app(AddonInstaller::class)->install('badmenu'))->toThrow(AddonException::class)
        ->and(Addon::find('badmenu'))->toBeNull();

    // 修好菜单定义后可直接重装（迁移已在首次尝试中执行过，重跑为幂等空操作）
    file_put_contents(storage_path('framework/addon-fixture/badmenu/database/menus.php'),
        "<?php\n\nreturn [['name' => 'badmenu.x', 'title' => 'X', 'view_path' => 'x/index']];\n");
    app(AddonInstaller::class)->install('badmenu');
    expect(Addon::find('badmenu'))->not->toBeNull()
        ->and(Menu::where('addon_key', 'badmenu')->count())->toBe(1);
});

it('插件不得占用系统菜单名：拒绝且原菜单不受影响', function () {
    make_addon_with_menus('hijack', [
        ['name' => 'system', 'title' => '冒牌系统', 'route_path' => '/evil'],
    ]);

    expect(fn () => app(AddonInstaller::class)->install('hijack'))->toThrow(AddonException::class)
        ->and(Menu::where('name', 'system')->value('addon_key'))->toBe('')
        ->and(Menu::where('name', 'system')->value('title'))->toBe('系统管理')
        ->and(Addon::find('hijack'))->toBeNull();
});

it('插件之间菜单名不得冲突：后装者被拒，先装者不受影响', function () {
    $menus = [['name' => 'shared.item', 'title' => '共享', 'view_path' => 'item/index']];
    make_addon_with_menus('pluga', $menus);
    make_addon_with_menus('plugb', $menus);

    app(AddonInstaller::class)->install('pluga');
    expect(fn () => app(AddonInstaller::class)->install('plugb'))->toThrow(AddonException::class)
        ->and(Menu::where('name', 'shared.item')->value('addon_key'))->toBe('pluga')
        ->and(Addon::find('plugb'))->toBeNull();
});
