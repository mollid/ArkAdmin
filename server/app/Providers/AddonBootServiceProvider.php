<?php

namespace App\Providers;

use App\Support\Addon\AddonInstaller;
use App\Support\Addon\AddonManager;
use Illuminate\Support\ServiceProvider;

/** 插件系统引导入口（§6.4），在 bootstrap/app.php 以 withProviders 注册 */
class AddonBootServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AddonManager::class);
        // installer 带"最近一次前端同步结果"状态，必须与命令/调用方共享同一实例
        $this->app->singleton(AddonInstaller::class);
    }

    public function boot(): void
    {
        $this->app->make(AddonManager::class)->boot();
    }
}
