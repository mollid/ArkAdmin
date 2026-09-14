<?php

namespace App\Support\Http\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;

trait ApiResponse
{
    protected function success($data = null, string $msg = 'ok'): JsonResponse
    {
        return response()->json(['code' => 0, 'data' => $data, 'msg' => $msg]);
    }

    protected function fail(int $code = 1, string $msg = '', $data = null): JsonResponse
    {
        return response()->json(['code' => $code, 'data' => $data, 'msg' => $msg]);
    }

    protected function paginate(LengthAwarePaginator $p): JsonResponse
    {
        return $this->success([
            'list' => $p->items(),
            'total' => $p->total(),
            'page' => $p->currentPage(),
            'per_page' => $p->perPage(),
        ]);
    }
}
