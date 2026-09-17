<?php

namespace App\Admin\Http\Controllers;

use App\Admin\Models\Admin;
use App\Support\Addon\AddonManager;
use App\Support\Http\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Widget 清单下发（harness 规格 §3.1）：仅启用插件、且当前管理员拥有声明权限的 widget。
 * 登录即可见性由权限过滤保证，端点本身不再要求额外权限串。
 */
class WidgetController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        /** @var Admin $admin */
        $admin = $request->user('admin');
        $perms = $admin->hasRole(config('arkadmin.super_role', 'super_admin'))
            ? null
            : $admin->getAllPermissions()->pluck('name')->flip();

        $widgets = [];
        foreach ($this->manager->widgets() as $widget) {
            $permission = (string) ($widget['permission'] ?? '');
            if ($permission !== '' && ($perms !== null && ! $perms->has($permission))) {
                continue;
            }
            $widgets[] = $widget;
        }
        usort($widgets, fn ($a, $b) => ($a['sort'] ?? 0) <=> ($b['sort'] ?? 0));

        return $this->success($widgets);
    }

    public function __construct(protected AddonManager $manager) {}
}
