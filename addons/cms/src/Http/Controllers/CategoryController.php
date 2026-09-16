<?php

namespace Addons\cms\Http\Controllers;

use Addons\cms\Models\Category;
use Addons\cms\Services\CategoryService;
use Addons\cms\Support\CmsException;
use App\Http\Controllers\Controller;
use App\Support\Http\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    use ApiResponse;

    public function __construct(protected CategoryService $service) {}

    public function index(): JsonResponse
    {
        return $this->success($this->service->tree());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'parent_id' => 'nullable|integer|min:0',
            'name' => 'required|string|max:64',
            'description' => 'nullable|string|max:255',
            'sort' => 'nullable|integer|min:0',
            'is_show' => 'nullable|boolean',
        ]);

        return $this->success(['id' => $this->service->store($data)->id], '创建成功');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $category = Category::findOrFail($id);
        $data = $request->validate([
            'parent_id' => 'nullable|integer|min:0',
            'name' => 'sometimes|required|string|max:64',
            'description' => 'nullable|string|max:255',
            'sort' => 'nullable|integer|min:0',
            'is_show' => 'nullable|boolean',
        ]);
        try {
            $this->service->update($category, $data);
        } catch (CmsException $e) {
            return $this->fail(1, $e->getMessage());
        }

        return $this->success(null, '更新成功');
    }

    public function destroy(int $id): JsonResponse
    {
        $category = Category::findOrFail($id);
        try {
            $this->service->destroy($category);
        } catch (CmsException $e) {
            return $this->fail(1, $e->getMessage());
        }

        return $this->success(null, '删除成功');
    }
}
