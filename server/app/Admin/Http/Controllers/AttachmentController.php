<?php

namespace App\Admin\Http\Controllers;

use App\Admin\Models\Attachment;
use App\Admin\Services\AttachmentService;
use App\Support\Http\PgLike;
use App\Support\Http\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
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
            'date_from' => 'nullable|date_format:Y-m-d',
            'date_to' => 'nullable|date_format:Y-m-d',
            'uploader_id' => 'nullable|integer|min:1',
        ]);
        $q = Attachment::query()
            // PG 的 LIKE 默认转义符是反斜杠；%/_/\ 按字面量匹配
            ->when($request->filled('keyword'), fn ($q) => $q->where('name', 'ilike',
                PgLike::wrap((string) $request->input('keyword'))))
            ->when($request->input('type') === 'image', fn ($q) => $q->where('mime', 'like', 'image/%'))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('created_at', '>=', $request->input('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('created_at', '<=', $request->input('date_to')))
            ->when($request->filled('uploader_id'), fn ($q) => $q->where('uploader_id', (int) $request->input('uploader_id')))
            ->orderByDesc('id');

        return $this->paginate($q->paginate((int) $request->input('per_page', 15)));
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file',
                'max:'.(int) config('arkadmin.attachment.max_size', 10240),
                // 白名单按 finfo 内容嗅探的 mime 判断：客户端 mime/文件名均可伪造，不作依据
                function (string $attribute, mixed $value, \Closure $fail) {
                    $mimes = (array) config('arkadmin.attachment.mimes', []);
                    $sniffed = $value instanceof UploadedFile
                        ? AttachmentService::sniffedMime($value) : '';
                    if (! array_key_exists($sniffed, $mimes)) {
                        $fail('文件类型不在允许范围内');
                    }
                }],
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
