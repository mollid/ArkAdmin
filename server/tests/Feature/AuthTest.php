<?php

use App\Admin\Models\Admin;
use App\Admin\Seeds\MenuSeeder;
use App\Admin\Seeds\RbacSeeder;

// me 用例断言菜单树，需同时播种菜单
beforeEach(function () {
    (new RbacSeeder)->run();
    (new MenuSeeder)->run();
});

it('logs in with correct credentials', function () {
    $r = $this->postJson('/api/admin/auth/login', ['username' => 'admin', 'password' => '123456']);
    $r->assertOk()->assertJsonPath('code', 0);
    expect($r->json('data.token'))->toBeString();
});

it('rejects wrong password with envelope', function () {
    $r = $this->postJson('/api/admin/auth/login', ['username' => 'admin', 'password' => 'nope']);
    $r->assertOk()->assertJsonPath('code', 1);
});

it('me returns menus and permissions for super admin', function () {
    $token = $this->postJson('/api/admin/auth/login', ['username' => 'admin', 'password' => '123456'])
        ->json('data.token');
    $r = $this->withToken($token)->getJson('/api/admin/auth/me');
    $r->assertOk();
    expect(collect($r->json('data.permissions')))->toContain('system.admin.index')
        ->and(collect($r->json('data.menus'))->pluck('name'))->toContain('system');
});

it('logout revokes token', function () {
    $token = $this->postJson('/api/admin/auth/login', ['username' => 'admin', 'password' => '123456'])
        ->json('data.token');
    $this->withToken($token)->deleteJson('/api/admin/auth/logout')->assertOk();
    // 同一测试进程内 guard 实例会缓存已认证用户，须重置后才会真正重查 token
    $this->app->make('auth')->forgetGuards();
    $this->withToken($token)->getJson('/api/admin/auth/me')->assertStatus(401);
});
