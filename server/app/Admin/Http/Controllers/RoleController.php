<?php

namespace App\Admin\Http\Controllers;

use App\Support\Http\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    use ApiResponse;

    public function index(): JsonResponse
    {
        $roles = Role::where('guard_name', 'admin')->with('permissions')->orderBy('id')->get()
            ->map(fn (Role $r) => [
                'id' => $r->id, 'name' => $r->name,
                'permissions' => $r->permissions->pluck('name'),
                'created_at' => $r->created_at?->toDateTimeString(),
            ]);
        return $this->success($roles);
    }

    public function permissions(): JsonResponse
    {
        $list = Permission::where('guard_name', 'admin')->orderBy('name')->get(['name', 'module'])
            ->map(fn ($p) => ['name' => $p->name, 'module' => $p->module]);
        return $this->success($list->groupBy('module'));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:64', Rule::unique('roles', 'name')->where('guard_name', 'admin')],
            'permissions' => 'nullable|array',
            'permissions.*' => ['string', Rule::exists('permissions', 'name')->where('guard_name', 'admin')],
        ]);
        $role = Role::create(['name' => $data['name'], 'guard_name' => 'admin']);
        $role->syncPermissions($data['permissions'] ?? []);
        return $this->success(['id' => $role->id], '创建成功');
    }

    public function update(Request $request, int $role): JsonResponse
    {
        $model = Role::where('guard_name', 'admin')->findOrFail($role);
        if ($model->name === 'super_admin') {
            return $this->fail(1, '超级管理员角色不可修改');
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:64', Rule::unique('roles', 'name')->ignore($model->id)->where('guard_name', 'admin')],
            'permissions' => 'nullable|array',
            'permissions.*' => ['string', Rule::exists('permissions', 'name')->where('guard_name', 'admin')],
        ]);
        $model->update(['name' => $data['name']]);
        $model->syncPermissions($data['permissions'] ?? []);
        return $this->success(null, '更新成功');
    }

    public function destroy(int $role): JsonResponse
    {
        $model = Role::where('guard_name', 'admin')->findOrFail($role);
        if ($model->name === 'super_admin') {
            return $this->fail(1, '超级管理员角色不可删除');
        }
        DB::transaction(function () use ($model) {
            $model->users()->detach();
            $model->delete();
        });
        return $this->success(null, '删除成功');
    }
}
