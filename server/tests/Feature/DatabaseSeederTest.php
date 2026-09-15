<?php

use App\Admin\Models\Admin;
use App\Admin\Models\Menu;
use Illuminate\Support\Facades\DB;

// db:seed 必须直接种出可登录的后端（此前只种 web 侧 User factory，admin 靠手工 RbacSeeder）
it('provisions the admin backend and skips web users', function () {
    $this->artisan('db:seed')->assertSuccessful();

    expect(Admin::where('username', 'admin')->exists())->toBeTrue()
        ->and(Menu::where('name', 'system')->exists())->toBeTrue()
        ->and(DB::table('users')->count())->toBe(0);
});
