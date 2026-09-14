<?php

namespace App\Admin\Services;

use App\Admin\Models\Admin;
use App\Admin\Models\Menu;
use Illuminate\Support\Collection;

class MenuService
{
    /** 菜单树（含无权限过滤），结构字段与 menus 表一致 + children */
    public function treeFor(Admin $admin): array
    {
        $perms = null;
        if (!$admin->hasRole('super_admin')) {
            $perms = $admin->getAllPermissions()->pluck('name')->flip();
        }

        $visible = Menu::orderBy('sort')->get()
            ->filter(fn (Menu $m) => $m->is_show)
            ->filter(fn (Menu $m) => $perms === null || $m->permission === '' || $perms->has($m->permission))
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
