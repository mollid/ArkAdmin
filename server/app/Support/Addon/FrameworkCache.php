<?php

namespace App\Support\Addon;

use Illuminate\Support\Facades\Artisan;

/** §11 风险对策：config/route/event 缓存在用时自动重建（装/停/卸/升级/ark:sync 共用）。失败降级为告警 */
class FrameworkCache
{
    public function __construct(protected ?string $cachePath = null)
    {
        // 注入点（M8 直测隔离）：默认指真实 bootstrap/cache，测试指向临时目录避免污染
        $this->cachePath ??= base_path('bootstrap/cache');
    }

    public function rebuild(): void
    {
        $rebuild = function (string $command): void {
            try {
                Artisan::call($command);
            } catch (\Throwable $e) {
                logger()->warning("框架缓存重建失败（{$command}）：".$e->getMessage());
            }
        };
        if (is_file($this->cachePath.'/config.php')) {
            $rebuild('config:cache');
        }
        if (is_file($this->cachePath.'/events.php')) {
            $rebuild('event:cache');
        }
        if (glob($this->cachePath.'/routes-*.php')) {
            $rebuild('route:cache');
        }
    }
}
