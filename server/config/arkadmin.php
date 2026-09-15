<?php

// ArkAdmin 框架级配置（§6.2 support_version 与此处的 version 比较）
return [
    'version' => env('ARKADMIN_VERSION', '0.1.0'),

    // 插件根目录；仓库根 addons/，server/addons 为其软链（§4）
    'addon_path' => env('ARKADMIN_ADDON_PATH', base_path('addons')),
];
