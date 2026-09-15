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

it('allows same role name under another guard', function () {
    Role::create(['name' => 'dup', 'guard_name' => 'web']);
    $this->withToken($this->token)->postJson('/api/admin/roles', ['name' => 'dup'])->assertOk();
    expect(Role::where('name', 'dup')->where('guard_name', 'admin')->exists())->toBeTrue();
});

it('rejects permissions belonging to another guard', function () {
    \Spatie\Permission\Models\Permission::create(['name' => 'web.perm', 'guard_name' => 'web']);
    $this->withToken($this->token)->postJson('/api/admin/roles', [
        'name' => 'r2', 'permissions' => ['web.perm'],
    ])->assertStatus(422);
});

it('rolls back user detach when role deletion fails', function () {
    $role = Role::create(['name' => 'boom', 'guard_name' => 'admin']);
    $holder = \App\Admin\Models\Admin::create(['username' => 'holder', 'password' => 'x123456', 'status' => 1]);
    $holder->assignRole($role);

    Role::deleting(fn ($r) => $r->name === 'boom' ? throw new \RuntimeException('boom') : null);

    $this->withToken($this->token)->deleteJson("/api/admin/roles/{$role->id}")->assertStatus(500);
    expect($holder->refresh()->hasRole('boom'))->toBeTrue();
});
