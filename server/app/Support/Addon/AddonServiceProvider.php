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
            // 逐个注册字符串类名（自动解析 handle 方法）；整组数组会被当作 [class, method] 解析
            foreach ((array) $listeners as $listener) {
                Event::listen($event, $listener);
            }
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
        // api 组与框架 routes/api.php 一致（throttle/参数绑定）；插件路由在 boot 期独立挂载，
        // 不在框架 api 组闭包内，必须显式带上
        Route::middleware(['api', 'auth:admin'])
            ->prefix('api/admin/addon/'.$this->info->name)
            ->group($file);
    }
}
