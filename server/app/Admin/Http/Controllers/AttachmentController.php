<?php

namespace App\Admin\Http\Controllers;

use App\Admin\Models\Attachment;
use App\Admin\Services\AttachmentService;
use App\Support\Http\PgLike;
use App\Support\Http\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class AttachmentController extends Controller
{
    use ApiResponse;

    public function __construct(protected AttachmentService $service) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'per_page' => 'nullable|integer|min:1|max:100',
            'keyword' => 'nullable|string|max:191',
            'type' => 'nullable|string|in:image',
        ]);
        $q = Attachment::query()
            // PG 的 LIKE 默认转义符是反斜杠；%/_/\ 按字面量匹配
            ->when($request->filled('keyword'), fn ($q) => $q->where('name', 'ilike',
                PgLike::wrap((string) $request->input('keyword'))))
            ->when($request->input('type') === 'image', fn ($q) => $q->where('mime', 'like', 'image/%'))
            ->orderByDesc('id');

        return $this->paginate($q->paginate((int) $request->input('per_page', 15)));
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            // mimes 按文件内容嗅探扩展名（不信任客户端提供的文件名/类型）
            'file' => ['required', 'file',
                'max:'.(int) config('arkadmin.attachment.max_size', 10240),
                'mimes:'.implode(',', (array) config('arkadmin.attachment.extensions'))],
        ], [], ['file' => '文件']);

        return $this->success(
            $this->service->store($request->file('file'), $request->user('admin')),
            '上传成功'
        );
    }

    public function destroy(int $attachment): JsonResponse
    {
        $model = Attachment::findOrFail($attachment);
        $this->service->destroy($model);

        return $this->success(null, '删除成功');
    }
}
