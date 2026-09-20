<?php

namespace App\Admin\Http\Controllers;

use App\Admin\Http\Requests\AdminStoreRequest;
use App\Admin\Http\Requests\AdminUpdateRequest;
use App\Admin\Models\Admin;
use App\Support\Http\PgLike;
use App\Support\Http\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Spatie\Permission\Models\Role;

class AdminController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'per_page' => 'nullable|integer|min:1|max:100',
            'keyword' => 'nullable|string|max:191',
            'status' => 'nullable|integer|in:0,1',
            'date_from' => 'nullable|date_format:Y-m-d',
            'date_to' => 'nullable|date_format:Y-m-d',
        ]);
        $q = Admin::query()
            ->with('roles')
            ->when($request->filled('keyword'), fn ($q) => $q->where(function ($q) use ($request) {
                $kw = PgLike::wrap((string) $request->input('keyword'));
                $q->where('username', 'ilike', $kw)->orWhere('name', 'ilike', $kw);
            }))
            ->when($request->filled('username'), fn ($q) => $q->where('username', 'ilike',
                PgLike::wrap((string) $request->input('username'))))   // 旧参数保留兼容
            ->when($request->filled('name'), fn ($q) => $q->where('name', 'ilike',
                PgLike::wrap((string) $request->input('name'))))
            // status=0 是合法筛选值，不能用 filled() 判（对 0 返回 false）
            ->when(isset($request->all()['status']) && $request->input('status') !== '', fn ($q) => $q->where('status', (int) $request->input('status')))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('created_at', '>=', $request->input('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('created_at', '<=', $request->input('date_to')))
            ->orderByDesc('id');

        // 编辑回填契约：每行附 roles（角色 id 数组）——覆盖预载的 Role 模型集合，避免序列化出完整角色对象
        return $this->paginate(
            $q->paginate((int) $request->input('per_page', 15))
                ->through(fn (Admin $a) => $a->setRelation('roles', $a->roles->pluck('id')))
        );
    }

    public function store(AdminStoreRequest $request): JsonResponse
    {
        $data = $request->validated();
        $roles = Role::where('guard_name', 'admin')->whereIn('id', $data['roles'] ?? [])->get();
        $admin = Admin::create($data);
        $admin->syncRoles($roles);

        return $this->success(['id' => $admin->id], '创建成功');
    }

    public function update(AdminUpdateRequest $request, int $admin): JsonResponse
    {
        $model = Admin::findOrFail($admin);
        $data = $request->validated();
        if (empty($data['password'])) {
            unset($data['password']);
        }
        // username 显式 null 与空密码同语义：不修改
        if (array_key_exists('username', $data) && $data['username'] === null) {
            unset($data['username']);
        }
        $model->update($data);
        $roles = Role::where('guard_name', 'admin')->whereIn('id', $data['roles'] ?? [])->get();
        $model->syncRoles($roles);

        return $this->success(null, '更新成功');
    }

    public function destroy(int $admin): JsonResponse
    {
        $model = Admin::findOrFail($admin);
        $me = request()->user('admin');
        if ($model->id === $me->id) {
            return $this->fail(1, '不能删除当前登录账号');
        }
        if ($model->hasRole('super_admin') && Admin::role('super_admin')->count() === 1) {
            return $this->fail(1, '不能删除最后一个超级管理员');
        }
        $model->delete();

        return $this->success(null, '删除成功');
    }
}
