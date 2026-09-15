<?php

namespace Addons\demo;

use App\Support\Addon\AddonServiceProvider as BaseProvider;

class AddonServiceProvider extends BaseProvider
{
    protected array $listen = [
        \App\Admin\Events\AdminLoginSuccessed::class => [
            \Addons\demo\Listeners\RecordAdminLogin::class,
        ],
    ];
}
