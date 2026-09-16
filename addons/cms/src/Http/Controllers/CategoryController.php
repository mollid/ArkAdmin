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
            'parent_id' => $this->parentIdRules(),
            'name' => 'required|string|max:64',
            'description' => 'nullable|string|max:255',
            'sort' => 'nullable|integer|min:0',
            'is_show' => 'nullable|boolean',
        ]);
        // nullable 放行的显式 null 按顶级处理：直接入库会撞 parent_id NOT NULL
        $data['parent_id'] = (int) ($data['parent_id'] ?? 0);

        return $this->success(['id' => $this->service->store($data)->id], '创建成功');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $category = Category::findOrFail($id);
        $data = $request->validate([
            'parent_id' => $this->parentIdRules(),
            'name' => 'sometimes|required|string|max:64',
            'description' => 'nullable|string|max:255',
            'sort' => 'nullable|integer|min:0',
            'is_show' => 'nullable|boolean',
        ]);
        if (array_key_exists('parent_id', $data)) {
            $data['parent_id'] = (int) ($data['parent_id'] ?? 0);
        }
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

    /**
     * parent_id 规则（store/update 共用）：0 = 顶级（没有对应行）；>0 必须真实存在。
     * 不校验存在性会造出树中不可见、界面上无法编辑/删除的孤儿栏目
     * （后台树按 parent_id 从 0 递归渲染），且其文章不计入任何栏目统计。
     *
     * @return array<int, mixed>
     */
    protected function parentIdRules(): array
    {
        return [
            'nullable', 'integer', 'min:0',
            function (string $attribute, mixed $value, \Closure $fail): void {
                if ((int) $value !== 0 && ! Category::query()->whereKey((int) $value)->exists()) {
                    $fail('上级栏目不存在');
                }
            },
        ];
    }
}
