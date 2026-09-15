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

it('allows parent_id=0 for top-level menus', function () {
    // 新建顶级菜单：parent_id=0 表示挂根，不得被 exists 规则拒绝为 422
    $r = $this->withToken($this->token)->postJson('/api/admin/menus', [
        'parent_id' => 0, 'name' => 'site', 'title' => '站点', 'icon' => 'Monitor',
        'route_path' => '/site', 'view_path' => 'site/index', 'permission' => '', 'sort' => 50, 'is_show' => true,
    ]);
    $r->assertOk();
    expect(Menu::where('name', 'site')->first()?->parent_id)->toBe(0);

    // 编辑既有顶层菜单（dashboard）回传 parent_id=0 同样必须通过
    $id = Menu::where('name', 'dashboard')->first()->id;
    $this->withToken($this->token)->putJson("/api/admin/menus/{$id}", [
        'parent_id' => 0, 'name' => 'dashboard', 'title' => '控制台', 'icon' => 'Odometer',
        'route_path' => '/dashboard', 'view_path' => 'dashboard/index', 'permission' => '', 'sort' => 0, 'is_show' => true,
    ])->assertOk();
});

it('rolls back the whole subtree when recursive deletion fails midway', function () {
    $top = Menu::create(['parent_id' => 0, 'name' => 'top', 'title' => '顶', 'sort' => 0, 'is_show' => true]);
    $mid = Menu::create(['parent_id' => $top->id, 'name' => 'mid', 'title' => '中', 'sort' => 0, 'is_show' => true]);
    Menu::create(['parent_id' => $mid->id, 'name' => 'leaf', 'title' => '叶', 'sort' => 0, 'is_show' => true]);

    // 中间节点删除时抛异常：无事务时叶子已被物理删除、链路残缺
    Menu::deleting(fn (Menu $m) => $m->name === 'mid' ? throw new \RuntimeException('boom') : null);

    $this->withToken($this->token)->deleteJson("/api/admin/menus/{$top->id}")->assertStatus(500);
    expect(Menu::whereIn('name', ['top', 'mid', 'leaf'])->count())->toBe(3);
});
