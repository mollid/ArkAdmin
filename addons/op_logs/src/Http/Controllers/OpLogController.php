<?php

namespace Addons\op_logs\Http\Controllers;

use Addons\op_logs\Models\OpLog;
use App\Http\Controllers\Controller;
use App\Support\Http\PgLike;
use App\Support\Http\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OpLogController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'per_page' => 'nullable|integer|min:1|max:100',
            'keyword' => 'nullable|string|max:191',
            'admin_id' => 'nullable|integer|min:1',
        ]);

        $keyword = (string) $request->input('keyword', '');

        return $this->paginate(
            OpLog::query()->with('admin:id,username')
                ->when($keyword !== '', fn ($q) => $q->where('route', 'ilike', PgLike::wrap($keyword)))
                ->when($request->filled('admin_id'), fn ($q) => $q->where('admin_id', (int) $request->input('admin_id')))
                ->orderByDesc('id')
                ->paginate((int) $request->input('per_page', 15))
        );
    }

    /** widget 卡片数据：今日操作数 / 登录成功数 */
    public function today(): JsonResponse
    {
        $today = OpLog::query()->whereDate('created_at', today());

        return $this->success([
            'operations' => (clone $today)->count(),
            'logins' => (clone $today)->where('route', 'api/admin/auth/login')->whereNotNull('admin_id')->count(),
        ]);
    }
}
