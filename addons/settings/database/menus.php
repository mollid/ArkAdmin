<?php

/*
 * 菜单定义（§6.5）：设置管理页。数据本体在核心 settings 基建（GET/PUT /api/admin/settings）。
 */
return [
    [
        'name' => 'settings.manage', 'title' => '系统设置', 'icon' => 'Tools',
        'route_path' => '/settings/manage', 'view_path' => 'manage/index',
        'permission' => 'addon.settings.manage', 'sort' => 95,
    ],
];
