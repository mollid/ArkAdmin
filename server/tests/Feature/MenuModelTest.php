<?php

use App\Admin\Seeds\MenuSeeder;
use App\Admin\Models\Menu;

it('seeds menu tree and is idempotent', function () {
    (new MenuSeeder)->run();
    (new MenuSeeder)->run();
    $dash = Menu::where('name', 'dashboard')->first();
    $sys = Menu::where('name', 'system')->first();
    expect($dash)->not->toBeNull()
        ->and($sys->children()->count())->toBe(3)
        ->and(Menu::where('name', 'system.admin')->first()->permission)->toBe('system.admin.index');
});
