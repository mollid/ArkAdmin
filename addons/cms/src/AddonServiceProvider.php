<?php

namespace Addons\cms;

use App\Support\Addon\AddonServiceProvider as BaseProvider;

class AddonServiceProvider extends BaseProvider
{
    // CMS 暂无事件监听；如需监听框架埋点（如 AttachmentSaved），登记到 $listen 即可
    protected array $listen = [];
}
