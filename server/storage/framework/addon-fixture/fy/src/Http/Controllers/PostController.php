<?php

namespace Addons\fy\Http\Controllers;

use Addons\fy\Http\Requests\PostStoreRequest;
use Addons\fy\Http\Requests\PostUpdateRequest;
use Addons\fy\Models\Post;
use Addons\fy\Services\PostService;
use App\Http\Controllers\Controller;
use App\Support\Http\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ark:crud 生成（表 fy_posts）。
 */
class PostController extends Controller
{
    use ApiResponse;

    public function __construct(protected PostService $service)
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
        return $this->success(Post::findOrFail($id));
    }

    public function store(PostStoreRequest $request): JsonResponse
    {
        return $this->success(['id' => $this->service->store($request->validated())->id], '创建成功');
    }

    public function update(PostUpdateRequest $request, int $id): JsonResponse
    {
        $post = Post::findOrFail($id);
        $this->service->update($post, $request->validated());

        return $this->success(null, '更新成功');
    }

    public function destroy(int $id): JsonResponse
    {
        Post::findOrFail($id)->delete();

        return $this->success(null, '删除成功');
    }
}
