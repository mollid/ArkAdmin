<?php

use App\Admin\Events\AdminOperationLogged;
use App\Admin\Seeds\RbacSeeder;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    (new RbacSeeder)->run();
});

it('写操作自动派发 AdminOperationLogged（脱敏参数/结果码/操作者）', function () {
    Event::fake([AdminOperationLogged::class]);
    $token = admin_token();

    $this->withToken($token)->postJson('/api/admin/admins', [
        'username' => 'u1', 'name' => '一号', 'password' => 'secret123', 'status' => 1,
    ])->assertOk();

    Event::assertDispatched(AdminOperationLogged::class, function (AdminOperationLogged $e) {
        return $e->method === 'POST'
            && $e->path === 'api/admin/admins'
            && $e->params['username'] === 'u1'
            && $e->params['password'] === '***'          // 脱敏
            && $e->admin?->username === 'admin'
            && $e->status === 200
            && $e->durationMs >= 0
            && filter_var($e->ip, FILTER_VALIDATE_IP) !== false;
    });
});

it('GET 不派发埋点', function () {
    $token = admin_token();   // 登录 POST 本身会埋点，先取 token 再 fake
    Event::fake([AdminOperationLogged::class]);
    $this->withToken($token)->getJson('/api/admin/admins')->assertOk();
    Event::assertNotDispatched(AdminOperationLogged::class);
});

it('登录失败派发（admin=null，携带 attempted username，密码脱敏）', function () {
    Event::fake([AdminOperationLogged::class]);
    $this->postJson('/api/admin/auth/login', ['username' => 'admin', 'password' => 'wrong']);

    Event::assertDispatched(AdminOperationLogged::class, function (AdminOperationLogged $e) {
        return $e->admin === null
            && $e->params['username'] === 'admin'
            && $e->params['password'] === '***';
    });
});

it('登录成功也派发（admin 归位）', function () {
    Event::fake([AdminOperationLogged::class]);
    $this->postJson('/api/admin/auth/login', ['username' => 'admin', 'password' => '123456'])->assertOk();
    Event::assertDispatched(AdminOperationLogged::class, fn (AdminOperationLogged $e) => $e->admin?->username === 'admin');
});

it('未登录写请求不炸（admin=null，仍派发）', function () {
    Event::fake([AdminOperationLogged::class]);
    $this->postJson('/api/admin/admins', ['username' => 'x', 'password' => 'x123456', 'status' => 1]); // 401
    Event::assertDispatched(AdminOperationLogged::class, fn (AdminOperationLogged $e) => $e->admin === null);
});
