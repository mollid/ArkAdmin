<?php

namespace App\Providers;

use App\Support\Addon\AddonManager;
use Illuminate\Support\ServiceProvider;

/** 插件系统引导入口（§6.4），在 bootstrap/app.php 以 withProviders 注册 */
class AddonBootServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AddonManager::class);
    }

    public function boot(): void
    {
        $this->app->make(AddonManager::class)->boot();
    }
}
