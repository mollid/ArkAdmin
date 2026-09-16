<?php

namespace Addons\cms\Http\Controllers;

use Addons\cms\Http\Requests\NoticeStoreRequest;
use Addons\cms\Http\Requests\NoticeUpdateRequest;
use Addons\cms\Models\Notice;
use Addons\cms\Services\NoticeService;
use App\Http\Controllers\Controller;
use App\Support\Http\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ark:crud 生成（表 cms_notices）。
 */
class NoticeController extends Controller
{
    use ApiResponse;

    public function __construct(protected NoticeService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'per_page' => 'nullable|integer|min:1|max:100',
            'keyword' => 'nullable|string|max:191',
        ]);

        return $this->paginate($this->service->paginate($request->only(['keyword', 'per_page'])));
    }

    public function show(int $id): JsonResponse
    {
        return $this->success(Notice::findOrFail($id));
    }

    public function store(NoticeStoreRequest $request): JsonResponse
    {
        return $this->success(['id' => $this->service->store($request->validated())->id], '创建成功');
    }

    public function update(NoticeUpdateRequest $request, int $id): JsonResponse
    {
        $notice = Notice::findOrFail($id);
        $this->service->update($notice, $request->validated());

        return $this->success(null, '更新成功');
    }

    public function destroy(int $id): JsonResponse
    {
        Notice::findOrFail($id)->delete();

        return $this->success(null, '删除成功');
    }
}
