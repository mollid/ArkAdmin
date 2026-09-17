<?php

namespace Addons\op_logs;

use Addons\op_logs\Console\PruneOpLogs;
use Addons\op_logs\Listeners\StoreAdminOperation;
use App\Admin\Events\AdminOperationLogged;
use App\Support\Addon\AddonServiceProvider as BaseProvider;

class AddonServiceProvider extends BaseProvider
{
    protected array $listen = [
        AdminOperationLogged::class => [
            StoreAdminOperation::class,
        ],
    ];

    public function boot(): void
    {
        parent::boot();
        $this->commands([PruneOpLogs::class]);
    }
}
