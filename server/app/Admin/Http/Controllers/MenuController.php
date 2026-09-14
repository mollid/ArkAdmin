<?php

namespace App\Admin\Http\Controllers;

use App\Admin\Models\Menu;
use App\Support\Http\Traits\ApiResponse;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class MenuController extends Controller
{
    use ApiResponse;

    public function index(): JsonResponse
    {
        $all = Menu::orderBy('sort')->get();
        return $this->success($this->nest($all, 0));
    }

    protected function nest($nodes, int $parentId): array
    {
        return $nodes->where('parent_id', $parentId)->map(function (Menu $m) use ($nodes) {
            return [
                'id' => $m->id, 'parent_id' => $m->parent_id, 'name' => $m->name,
                'title' => $m->title, 'icon' => $m->icon, 'route_path' => $m->route_path,
                'view_path' => $m->view_path, 'permission' => $m->permission,
                'addon_key' => $m->addon_key, 'sort' => $m->sort, 'is_show' => $m->is_show,
                'children' => $this->nest($nodes, $m->id),
            ];
        })->values()->all();
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $menu = Menu::create($data);
        return $this->success(['id' => $menu->id], '创建成功');
    }

    public function update(Request $request, int $menu): JsonResponse
    {
        $model = Menu::findOrFail($menu);
        $data = $this->validated($request, $model->id);
        if (!$this->parentAllowed($model, (int) $data['parent_id'])) {
            return $this->fail(1, '父级菜单不能是自己或自己的子菜单');
        }
        $model->update($data);
        return $this->success(null, '更新成功');
    }

    public function destroy(int $menu): JsonResponse
    {
        $model = Menu::findOrFail($menu);
        foreach ($model->children()->get() as $child) {
            $this->deleteRecursive($child);
        }
        $model->delete();
        return $this->success(null, '删除成功');
    }

    protected function deleteRecursive(Menu $m): void
    {
        foreach ($m->children()->get() as $child) {
            $this->deleteRecursive($child);
        }
        $m->delete();
    }

    protected function parentAllowed(Menu $self, int $parentId): bool
    {
        if ($self->id === $parentId) {
            return false;
        }
        $cur = Menu::find($parentId);
        while ($cur) {
            if ($cur->id === $self->id) {
                return false;
            }
            $cur = $cur->parent_id ? Menu::find($cur->parent_id) : null;
        }
        return true;
    }

    protected function validated(Request $request, ?int $ignoreId = null): array
    {
        $unique = $ignoreId
            ? \Illuminate\Validation\Rule::unique('menus', 'name')->ignore($ignoreId)
            : 'unique:menus,name';
        $data = $request->validate([
            // 0 = 挂根（顶级菜单）：exists 规则无对应行必 422，闭包放行 0、其余须存在
            'parent_id' => ['nullable', 'integer', function (string $attribute, $value, Closure $fail) {
                if ((int) $value !== 0 && !Menu::where('id', $value)->exists()) {
                    $fail('父级菜单不存在');
                }
            }],
            'name' => ['required', 'string', 'max:64', $unique],
            'title' => 'required|string|max:64',
            'icon' => 'nullable|string|max:64',
            'route_path' => 'nullable|string|max:191',
            'view_path' => 'nullable|string|max:191',
            'permission' => 'nullable|string|max:191',
            'sort' => 'nullable|integer',
            'is_show' => 'nullable|boolean',
        ]);
        // menus 表各列 NOT NULL 且有默认值：ConvertEmptyStringsToNull 会把空串/显式 null 传进 validated()，
        // 直接落库将触发 23502；此处将"已提交且为 null"的字段归一为列默认值（未提交的键不动，保持部分更新语义）
        foreach (['parent_id' => 0, 'icon' => '', 'route_path' => '', 'view_path' => '',
                  'permission' => '', 'sort' => 0, 'is_show' => true] as $key => $default) {
            if (array_key_exists($key, $data) && $data[$key] === null) {
                $data[$key] = $default;
            }
        }
        return $data + ['parent_id' => (int) $request->input('parent_id', 0)];
    }
}
