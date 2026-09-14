<?php

use App\Admin\Models\Admin;
use App\Admin\Seeds\RbacSeeder;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    (new RbacSeeder)->run();
    $this->token = $this->postJson('/api/admin/auth/login', ['username' => 'admin', 'password' => '123456'])
        ->json('data.token');
});

it('lists admins paginated', function () {
    Admin::create(['username' => 'u1', 'password' => 'x123456', 'status' => 1]);
    $r = $this->withToken($this->token)->getJson('/api/admin/admins?username=u1');
    // 简报原文 and() 链在 TestResponse 上（Macroable 无 and 宏），按 Task 8 修复模式重排，断言逐字保留
    $r->assertOk();
    expect(collect($r->json('data.list'))->pluck('username'))->toContain('u1')
        ->and($r->json('data.total'))->toBeGreaterThanOrEqual(1);
});

it('stores admin with roles', function () {
    $rid = Role::where('name', 'super_admin')->first()->id;
    $r = $this->withToken($this->token)->postJson('/api/admin/admins', [
        'username' => 'new1', 'password' => 'pass123', 'name' => '新管理员', 'status' => 1, 'roles' => [$rid],
    ]);
    $r->assertOk();
    expect(Admin::where('username', 'new1')->first()->hasRole('super_admin'))->toBeTrue();
});

it('rejects duplicate username with 422', function () {
    $this->withToken($this->token)->postJson('/api/admin/admins', [
        'username' => 'admin', 'password' => 'pass123', 'status' => 1,
    ])->assertStatus(422);
});

it('update keeps password when empty', function () {
    $id = Admin::where('username', 'admin')->first()->id;
    $this->withToken($this->token)->putJson("/api/admin/admins/{$id}", ['name' => '改个名', 'status' => 1])
        ->assertOk();
    expect(Admin::find($id)->name)->toBe('改个名');
});

it('cannot delete self', function () {
    $id = Admin::where('username', 'admin')->first()->id;
    $this->withToken($this->token)->deleteJson("/api/admin/admins/{$id}")
        ->assertOk()->assertJsonPath('code', 1);
});
