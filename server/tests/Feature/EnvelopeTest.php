<?php

use App\Support\Http\Traits\ApiResponse;
use Illuminate\Pagination\LengthAwarePaginator;

// 信封演示类只在本文件用，收进测试函数作用域避免泄漏为全局符号
function envelopeDemo(): object
{
    return new class
    {
        use ApiResponse;

        public function demoSuccess($data)
        {
            return $this->success($data);
        }

        public function demoPaginate(LengthAwarePaginator $p)
        {
            return $this->paginate($p);
        }
    };
}

it('wraps success data', function () {
    $r = envelopeDemo()->demoSuccess(['x' => 1]);

    $this->assertSame(200, $r->getStatusCode());
    $this->assertSame(
        ['code' => 0, 'data' => ['x' => 1], 'msg' => 'ok'],
        $r->getData(true)
    );
});

it('wraps paginate as list total', function () {
    $p = new LengthAwarePaginator([['id' => 1]], 1, 15, 1);
    $r = envelopeDemo()->demoPaginate($p);
    $data = $r->getData(true)['data'];

    foreach (['list', 'total', 'page', 'per_page'] as $key) {
        $this->assertArrayHasKey($key, $data);
    }
    $this->assertSame(1, $data['total']);
    $this->assertSame([['id' => 1]], $data['list']);
});

it('renders validation exception as envelope', function () {
    // 原 Task 4 __probe 探针已在 Task 8 移除；改用真实登录路由的必填校验验证异常信封渲染
    $resp = $this->postJson('/api/admin/auth/login');

    $resp->assertStatus(422);
    $this->assertSame(422, $resp->json('code'));
    $this->assertIsString($resp->json('msg'));
});
