<?php

namespace App\Admin\Http\Controllers;

use App\Admin\Models\Admin;
use App\Admin\Services\MenuService;
use App\Support\Http\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    use ApiResponse;

    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        $admin = Admin::where('username', $request->input('username'))->first();
        if (!$admin || !Hash::check($request->input('password'), $admin->password)) {
            return $this->fail(1, '用户名或密码错误');
        }
        if ($admin->status !== 1) {
            return $this->fail(1, '账号已禁用');
        }

        $token = $admin->createToken('admin')->plainTextToken;
        return $this->success([
            'token' => $token,
            'admin' => ['id' => $admin->id, 'username' => $admin->username, 'name' => $admin->name],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var Admin $admin */
        $admin = $request->user('admin');
        return $this->success([
            'admin' => ['id' => $admin->id, 'username' => $admin->username, 'name' => $admin->name],
            'roles' => $admin->getRoleNames(),
            // 超管返回全量具体权限（seeder 已同步全部），与验收测试断言一致；超管身份可经 roles 判断
            'permissions' => $admin->getAllPermissions()->pluck('name'),
            'menus' => (new MenuService)->treeFor($admin),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user('admin')->currentAccessToken()->delete();
        return $this->success();
    }
}
