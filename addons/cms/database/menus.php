<?php

/*
 * 插件菜单定义（§6.5）：写入时 addon_key 强制为 cms。
 * view_path 相对插件前端根（M3 复制到 admin/src/addons/cms/ 后生效）。
 */
return [
    [
        'name' => 'cms', 'title' => '内容管理', 'icon' => 'Document',
        'route_path' => '/cms', 'sort' => 150,
        'children' => [
            [
                'name' => 'cms.category', 'title' => '栏目管理', 'icon' => 'FolderOpened',
                'route_path' => '/cms/category', 'view_path' => 'category/index',
                'permission' => 'addon.cms.category.index', 'sort' => 1,
            ],
            [
                'name' => 'cms.article', 'title' => '文章管理', 'icon' => 'EditPen',
                'route_path' => '/cms/article', 'view_path' => 'article/index',
                'permission' => 'addon.cms.article.index', 'sort' => 2,
            ],
            // ark:crud:menus:start
            // ark:crud:menus:end
        ],
    ],
];
