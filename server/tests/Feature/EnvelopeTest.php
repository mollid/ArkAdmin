<?php

namespace Tests\Feature;

use App\Support\Http\Traits\ApiResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Tests\TestCase;

class EnvelopeDemo
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
}

class EnvelopeTest extends TestCase
{
    public function test_wraps_success_data(): void
    {
        $r = (new EnvelopeDemo)->demoSuccess(['x' => 1]);

        $this->assertSame(200, $r->getStatusCode());
        $this->assertSame(
            ['code' => 0, 'data' => ['x' => 1], 'msg' => 'ok'],
            $r->getData(true)
        );
    }

    public function test_wraps_paginate_as_list_total(): void
    {
        $p = new LengthAwarePaginator([['id' => 1]], 1, 15, 1);
        $r = (new EnvelopeDemo)->demoPaginate($p);
        $data = $r->getData(true)['data'];

        foreach (['list', 'total', 'page', 'per_page'] as $key) {
            $this->assertArrayHasKey($key, $data);
        }
        $this->assertSame(1, $data['total']);
        $this->assertSame([['id' => 1]], $data['list']);
    }

    public function test_renders_validation_exception_as_envelope(): void
    {
        // 原 Task 4 __probe 探针已在 Task 8 移除；改用真实登录路由的必填校验验证异常信封渲染
        $resp = $this->postJson('/api/admin/auth/login');

        $resp->assertStatus(422);
        $this->assertSame(422, $resp->json('code'));
        $this->assertIsString($resp->json('msg'));
    }
}
