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
];
