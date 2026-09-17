<?php

namespace Addons\op_logs;

use Addons\op_logs\Console\PruneOpLogs;
use App\Support\Addon\AddonServiceProvider as BaseProvider;

class AddonServiceProvider extends BaseProvider
{
    protected array $listen = [
        \App\Admin\Events\AdminOperationLogged::class => [
            \Addons\op_logs\Listeners\StoreAdminOperation::class,
        ],
    ];

    public function boot(): void
    {
        parent::boot();
        $this->commands([PruneOpLogs::class]);
    }
}
