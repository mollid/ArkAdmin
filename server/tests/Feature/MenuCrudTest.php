<?php

use App\Admin\Models\Menu;
use App\Admin\Seeds\MenuSeeder;
use App\Admin\Seeds\RbacSeeder;

beforeEach(function () {
    (new RbacSeeder)->run();
    (new MenuSeeder)->run();
    $this->token = $this->postJson('/api/admin/auth/login', ['username' => 'admin', 'password' => '123456'])
        ->json('data.token');
});

it('returns full menu tree for management', function () {
    $r = $this->withToken($this->token)->getJson('/api/admin/menus');
    $r->assertOk();
    expect(collect($r->json('data'))->pluck('name'))->toContain('dashboard', 'system');
});

it('creates a child menu', function () {
    $pid = Menu::where('name', 'system')->first()->id;
    $r = $this->withToken($this->token)->postJson('/api/admin/menus', [
        'parent_id' => $pid, 'name' => 'system.log', 'title' => '操作日志',
        'icon' => 'Document', 'route_path' => '/system/log', 'view_path' => 'system/log/index',
        'permission' => '', 'sort' => 9, 'is_show' => true,
    ]);
    $r->assertOk();
    expect(Menu::where('name', 'system.log')->first())->not->toBeNull();
});

it('rejects self as parent', function () {
    $id = Menu::where('name', 'system')->first()->id;
    $this->withToken($this->token)->putJson("/api/admin/menus/{$id}", [
        'parent_id' => $id, 'name' => 'system', 'title' => '系统管理', 'sort' => 100, 'is_show' => true,
        'icon' => 'Setting', 'route_path' => '/system', 'view_path' => '', 'permission' => '',
    ])->assertOk()->assertJsonPath('code', 1);
});

it('deletes menu and its children', function () {
    $id = Menu::where('name', 'system')->first()->id;
    $this->withToken($this->token)->deleteJson("/api/admin/menus/{$id}")->assertOk();
    expect(Menu::where('name', 'like', 'system%')->count())->toBe(0);
});
