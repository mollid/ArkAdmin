<?php

// ArkAdmin 框架级配置（§6.2 support_version 与此处的 version 比较）
return [
    'version' => env('ARKADMIN_VERSION', '0.1.0'),

    // 插件根目录；仓库根 addons/，server/addons 为其软链（§4）
    'addon_path' => env('ARKADMIN_ADDON_PATH', base_path('addons')),

    // admin 前端根目录（复制插件前端 admin/ → <此目录>/src/addons/<key>/）
    'admin_path' => env('ARKADMIN_ADMIN_PATH', dirname(base_path()).'/admin'),

    // 超管角色名：RbacSeeder 与插件安装共用（新增插件权限自动授予该角色，见 §5.3）
    'super_role' => 'super_admin',

    // 捆绑系统插件（M6 harness）：随 db:seed 幂等自动安装，与业务插件同机制装卸
    'system_addons' => ['settings', 'op_logs'],

    // 素材库（§5.4 attachments）：mimes 为「内容嗅探 mime → 存储扩展名」白名单；
    // 校验与扩展名均以 finfo 嗅探为准，不信任客户端 mime/文件名；max_size 单位 KB
    'attachment' => [
        'disk' => env('ARKADMIN_ATTACHMENT_DISK', 'public'),
        'mimes' => [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/bmp' => 'bmp',
            'image/x-ms-bmp' => 'bmp',
        ],
        'max_size' => (int) env('ARKADMIN_ATTACHMENT_MAX_SIZE', 10240),
    ],
];
