<?php

namespace App\Admin\Services;

use App\Admin\Models\Admin;
use App\Admin\Models\Menu;
use App\Support\Addon\Models\Addon;
use Illuminate\Support\Collection;

class MenuService
{
    /** 菜单树（含无权限过滤），结构字段与 menus 表一致 + children */
    public function treeFor(Admin $admin): array
    {
        $perms = null;
        if (!$admin->hasRole(config('arkadmin.super_role', 'super_admin'))) {
            $perms = $admin->getAllPermissions()->pluck('name')->flip();
        }

        // 禁用插件保留菜单行但不参与渲染（§6.3：仅已启用插件参与菜单渲染）；
        // addons 表缺失（手工回滚等异常态）时降级为不过滤，菜单接口不致命
        $disabledAddons = collect()->flip();
        try {
            $disabledAddons = Addon::query()->where('enabled', false)->pluck('name')->flip();
        } catch (\Illuminate\Database\QueryException $e) {
            logger()->warning('addons 注册表不可读，禁用插件菜单过滤跳过：'.$e->getMessage());
        }

        $visible = Menu::orderBy('sort')->get()
            ->filter(fn (Menu $m) => $m->is_show)
            ->filter(fn (Menu $m) => $m->addon_key === '' || !$disabledAddons->has($m->addon_key))
            // permission 为空串（或防御性地为 null）表示无权限要求；in_array 严格比较避免 '0' 被 PHP 判空
            ->filter(fn (Menu $m) => $perms === null || in_array($m->permission, ['', null], true) || $perms->has($m->permission))
            ->values();

        return $this->nest($visible, 0);
    }

    protected function nest(Collection $nodes, int $parentId): array
    {
        $out = [];
        foreach ($nodes->where('parent_id', $parentId) as $n) {
            $children = $this->nest($nodes, $n->id);
            // 目录型（无 view_path）且无可见子项则剔除
            if ($n->view_path === '' && !$children) {
                continue;
            }
            $out[] = [
                'id' => $n->id, 'parent_id' => $n->parent_id, 'name' => $n->name,
                'title' => $n->title, 'icon' => $n->icon, 'route_path' => $n->route_path,
                'view_path' => $n->view_path, 'permission' => $n->permission,
                'addon_key' => $n->addon_key, 'sort' => $n->sort,
                'children' => $children,
            ];
        }
        return $out;
    }
}
