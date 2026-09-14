<?php

use App\Admin\Seeds\RbacSeeder;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    (new RbacSeeder)->run();
    $this->token = $this->postJson('/api/admin/auth/login', ['username' => 'admin', 'password' => '123456'])
        ->json('data.token');
});

it('lists roles with permissions', function () {
    $r = $this->withToken($this->token)->getJson('/api/admin/roles');
    $r->assertOk();
    $super = collect($r->json('data.list') ?? $r->json('data'))->firstWhere('name', 'super_admin');
    expect($super)->not->toBeNull();
});

it('creates role with permissions', function () {
    $r = $this->withToken($this->token)->postJson('/api/admin/roles', [
        'name' => 'editor', 'permissions' => ['system.admin.index'],
    ]);
    $r->assertOk();
    expect(Role::findByName('editor', 'admin')->hasPermissionTo('system.admin.index'))->toBeTrue();
});

it('cannot delete super_admin', function () {
    $id = Role::findByName('super_admin', 'admin')->id;
    $this->withToken($this->token)->deleteJson("/api/admin/roles/{$id}")
        ->assertOk()->assertJsonPath('code', 1);
});

it('cannot update super_admin name', function () {
    $id = Role::findByName('super_admin', 'admin')->id;
    $this->withToken($this->token)->putJson("/api/admin/roles/{$id}", ['name' => 'hax'])
        ->assertOk()->assertJsonPath('code', 1);
});
