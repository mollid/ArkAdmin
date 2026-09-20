<?php

use App\Admin\Models\Admin;

beforeEach(function () {
    (new App\Admin\Seeds\RbacSeeder)->run();
});

function seed_admin(string $username, string $name, int $status, string $createdAt): Admin
{
    // 绕过 Eloquent 时间戳以精确控制 created_at（日期范围断言用）
    $admin = new Admin(['username' => $username, 'password' => 'x123456', 'name' => $name, 'status' => $status]);
    $admin->created_at = $createdAt;
    $admin->save();

    return $admin;
}

it('keyword 同时模糊匹配 username 与 name', function () {
    seed_admin('zhangsan', '张三', 1, '2026-09-01 10:00:00');
    seed_admin('lisi', '李四喵', 1, '2026-09-02 10:00:00');

    $rows = $this->getJson('/api/admin/admins?keyword=张三', ['Authorization' => 'Bearer '.admin_token()])
        ->assertOk()->json('data.list');
    expect(collect($rows)->pluck('username')->toArray())->toBe(['zhangsan']);
});

it('status 精确过滤', function () {
    seed_admin('on1', '启用者', 1, '2026-09-01 10:00:00');
    seed_admin('off1', '禁用者', 0, '2026-09-01 10:00:00');

    $rows = $this->getJson('/api/admin/admins?status=0', ['Authorization' => 'Bearer '.admin_token()])
        ->assertOk()->json('data.list');
    expect(collect($rows)->pluck('username')->toArray())->toBe(['off1']);
});

it('date_from/date_to 按 created_at 范围过滤', function () {
    seed_admin('early', '早的', 1, '2026-09-01 10:00:00');
    seed_admin('mid', '中的', 1, '2026-09-03 10:00:00');
    seed_admin('late', '晚的', 1, '2026-09-05 23:59:59');

    $rows = $this->getJson('/api/admin/admins?date_from=2026-09-02&date_to=2026-09-04', ['Authorization' => 'Bearer '.admin_token()])
        ->assertOk()->json('data.list');
    expect(collect($rows)->pluck('username')->toArray())->toBe(['mid']);
});

it('非法日期参数 422', function () {
    $this->getJson('/api/admin/admins?date_from=not-a-date', ['Authorization' => 'Bearer '.admin_token()])
        ->assertStatus(422);
});
