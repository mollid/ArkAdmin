<?php

use App\Admin\Seeds\MenuSeeder;
use App\Admin\Models\Menu;

it('seeds menu tree and is idempotent', function () {
    (new MenuSeeder)->run();
    (new MenuSeeder)->run();
    $dash = Menu::where('name', 'dashboard')->first();
    $sys = Menu::where('name', 'system')->first();
    expect($dash)->not->toBeNull()
        // M7 起 system 组含 4 个子菜单：管理员/角色/菜单/插件
        ->and($sys->children()->count())->toBe(4)
        ->and(Menu::where('name', 'system.addon')->first()->permission)->toBe('system.addon.index')
        ->and(Menu::where('name', 'system.admin')->first()->permission)->toBe('system.admin.index');
});
