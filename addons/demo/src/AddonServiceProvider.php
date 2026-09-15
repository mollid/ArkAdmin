<?php

namespace Addons\demo;

use App\Support\Addon\AddonServiceProvider as BaseProvider;

class AddonServiceProvider extends BaseProvider
{
    /** 事件监听在 M2 T7 接入框架登录埋点时补上 */
    protected array $listen = [];
}
