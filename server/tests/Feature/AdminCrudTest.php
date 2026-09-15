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

it('validates per_page as integer between 1 and 100', function () {
    foreach (['0', '999', 'abc'] as $bad) {
        $this->withToken($this->token)->getJson("/api/admin/admins?per_page={$bad}")->assertStatus(422);
    }
    $this->withToken($this->token)->getJson('/api/admin/admins?per_page=100')->assertOk();
});

it('treats ilike wildcards in search keywords as literals', function () {
    Admin::create(['username' => 'u1', 'password' => 'x123456', 'status' => 1]);
    // 未转义时 '%u1%%' 仍命中 u1；转义后 'u1%' 只匹配字面含 u1% 的用户名
    $r = $this->withToken($this->token)->getJson('/api/admin/admins?username=' . urlencode('u1%'));
    $r->assertOk();
    expect(collect($r->json('data.list'))->pluck('username'))->not->toContain('u1');
});

it('update treats explicit null username as do-not-change', function () {
    $id = Admin::where('username', 'admin')->first()->id;
    $this->withToken($this->token)->putJson("/api/admin/admins/{$id}", [
        'username' => null, 'name' => '改名不改账号', 'status' => 1,
    ])->assertOk();
    expect(Admin::find($id)->username)->toBe('admin');
});

it('rejects roles belonging to another guard', function () {
    $webRole = Role::create(['name' => 'webby', 'guard_name' => 'web']);
    $this->withToken($this->token)->postJson('/api/admin/admins', [
        'username' => 'g1', 'password' => 'pass123', 'status' => 1, 'roles' => [$webRole->id],
    ])->assertStatus(422);
});

// 契约回归锁：列表行 roles 恒为角色 id 数组（前端编辑回填依赖此形状）
it('returns roles as id array in list rows', function () {
    $editor = Role::firstOrCreate(['name' => 'editor', 'guard_name' => 'admin']);
    Admin::create(['username' => 'multi', 'password' => 'x123456', 'status' => 1])->syncRoles([$editor]);
    $row = collect($this->withToken($this->token)->getJson('/api/admin/admins?username=multi')->json('data.list'))
        ->firstWhere('username', 'multi');
    expect($row['roles'])->toBe([$editor->id]);
});

// 末位超管防呆：操作者须非本人且持 destroy 权限，目标为唯一超管
it('cannot delete the last super admin', function () {
    $ops = Admin::create(['username' => 'ops', 'password' => 'x123456', 'status' => 1]);
    $role = Role::firstOrCreate(['name' => 'ops_mgr', 'guard_name' => 'admin']);
    $role->syncPermissions(['system.admin.destroy']);
    $ops->assignRole($role);
    $token = $this->postJson('/api/admin/auth/login', ['username' => 'ops', 'password' => 'x123456'])->json('data.token');

    $id = Admin::where('username', 'admin')->first()->id;
    $this->withToken($token)->deleteJson("/api/admin/admins/{$id}")
        ->assertOk()->assertJsonPath('code', 1);
    expect(Admin::find($id))->not->toBeNull();
});
