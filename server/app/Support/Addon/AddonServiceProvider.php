<?php

namespace App\Support\Addon;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * 插件 ServiceProvider 基类：自定位插件目录（反射类文件 → <插件根>/src → <插件根>），
 * boot 时注册 $listen 事件监听并挂载 /api/admin/addon/<key> 路由（auth:admin）。
 * 插件侧只需继承本类，把监听映射写进 $listen。
 */
abstract class AddonServiceProvider extends ServiceProvider
{
    /** @var array<class-string, list<class-string>> 事件类 => 监听器类列表 */
    protected array $listen = [];

    protected AddonInfo $info;

    public function __construct(\Illuminate\Contracts\Foundation\Application $app)
    {
        parent::__construct($app);
        $dir = dirname((new \ReflectionClass(static::class))->getFileName(), 2);
        $this->info = AddonInfo::fromDir($dir);
    }

    public function register(): void
    {
    }

    public function boot(): void
    {
        foreach ($this->listen as $event => $listeners) {
            Event::listen($event, $listeners);
        }
        $this->mountRoutes();
    }

    /** public 供测试与引导层按需重挂（remount 场景） */
    public function mountRoutes(): void
    {
        $file = $this->info->routeFile();
        if (! is_file($file)) {
            return;
        }
        Route::middleware('auth:admin')
            ->prefix('api/admin/addon/'.$this->info->name)
            ->group($file);
    }
}
