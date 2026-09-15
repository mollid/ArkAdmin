<?php

/*
 * 插件菜单定义（§6.5）：写入时 addon_key 强制为插件名。
 * view_path 相对插件前端根（M3 复制到 admin/src/addons/demo/ 后生效）。
 */
return [
    [
        'name' => 'demo', 'title' => '演示插件', 'icon' => 'Box',
        'route_path' => '/demo', 'sort' => 200,
        'children' => [
            [
                'name' => 'demo.note', 'title' => '便签', 'icon' => 'Document',
                'route_path' => '/demo/note', 'view_path' => 'note/index',
                'permission' => 'addon.demo.note.index', 'sort' => 1,
            ],
        ],
    ],
];
