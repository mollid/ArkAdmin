<?php

namespace App\Admin\Seeds;

use App\Admin\Models\Menu;

class MenuSeeder
{
    public function run(): void
    {
        $upsert = function (array $attrs) {
            Menu::updateOrCreate(['name' => $attrs['name']], $attrs);
        };

        $upsert([
            'name' => 'dashboard', 'title' => '控制台', 'icon' => 'Odometer',
            'route_path' => '/dashboard', 'view_path' => 'dashboard/index',
            'sort' => 0, 'is_show' => true, 'addon_key' => '', 'permission' => '',
        ]);
        $upsert([
            'name' => 'attachment', 'title' => '素材库', 'icon' => 'Picture',
            'route_path' => '/attachment', 'view_path' => 'attachment/index',
            'sort' => 90, 'is_show' => true, 'addon_key' => '', 'permission' => 'system.attachment.index',
        ]);
        $upsert([
            'name' => 'system', 'title' => '系统管理', 'icon' => 'Setting',
            'route_path' => '/system', 'view_path' => '', 'sort' => 100,
            'is_show' => true, 'addon_key' => '', 'permission' => '',
        ]);
        $sysId = Menu::where('name', 'system')->value('id');
        foreach ([
            ['name' => 'system.admin', 'title' => '管理员管理', 'icon' => 'User',
                'route_path' => '/system/admin', 'view_path' => 'system/admin/index',
                'permission' => 'system.admin.index', 'sort' => 1],
            ['name' => 'system.role', 'title' => '角色管理', 'icon' => 'Avatar',
                'route_path' => '/system/role', 'view_path' => 'system/role/index',
                'permission' => 'system.role.index', 'sort' => 2],
            ['name' => 'system.menu', 'title' => '菜单管理', 'icon' => 'Menu',
                'route_path' => '/system/menu', 'view_path' => 'system/menu/index',
                'permission' => 'system.menu.index', 'sort' => 3],
        ] as $item) {
            $upsert($item + ['parent_id' => $sysId, 'icon' => $item['icon'],
                'is_show' => true, 'addon_key' => '', 'permission' => $item['permission']]);
        }
    }
}
