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

// timing 侧信道抹平：用户不存在时也必须执行一次同代价的哈希校验
it('runs a hash check even when the user does not exist', function () {
    \Illuminate\Support\Facades\Hash::shouldReceive('check')->atLeast()->once()->andReturn(false);

    $r = $this->postJson('/api/admin/auth/login', ['username' => 'ghost', 'password' => 'whatever']);
    $r->assertOk()->assertJsonPath('code', 1);
});

// 无 token 请求必须干净地 401（sanctum 回退 guard 已置空，API-only 不做 session 认证）
it('rejects tokenless me with 401', function () {
    $this->getJson('/api/admin/auth/me')->assertStatus(401);
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
