<?php

namespace Addons\cms\Http\Controllers;

use Addons\cms\Http\Requests\ArticleStoreRequest;
use Addons\cms\Http\Requests\ArticleUpdateRequest;
use Addons\cms\Models\Article;
use Addons\cms\Services\ArticleService;
use App\Http\Controllers\Controller;
use App\Support\Http\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ArticleController extends Controller
{
    use ApiResponse;

    public function __construct(protected ArticleService $service) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'per_page' => 'nullable|integer|min:1|max:100',
            'keyword' => 'nullable|string|max:191',
            'category_id' => 'nullable|integer|min:0',
            'status' => 'nullable|integer|in:0,1',
        ]);

        // 列表不拖富文本（content 可能很大），编辑时经 show 取全量
        return $this->paginate(
            $this->service->paginate($request->only(['keyword', 'category_id', 'status', 'per_page']))
                ->through(fn (Article $a) => $a->makeHidden('content'))
        );
    }

    public function show(int $id): JsonResponse
    {
        return $this->success(Article::query()->with('category:id,name')->findOrFail($id));
    }

    public function store(ArticleStoreRequest $request): JsonResponse
    {
        return $this->success(['id' => $this->service->store($request->validated())->id], '创建成功');
    }

    public function update(ArticleUpdateRequest $request, int $id): JsonResponse
    {
        $article = Article::findOrFail($id);
        $this->service->update($article, $request->validated());

        return $this->success(null, '更新成功');
    }

    public function destroy(int $id): JsonResponse
    {
        Article::findOrFail($id)->delete();

        return $this->success(null, '删除成功');
    }
}
