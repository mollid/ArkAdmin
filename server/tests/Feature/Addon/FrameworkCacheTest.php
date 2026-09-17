<?php

use App\Support\Addon\FrameworkCache;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

const CACHE_FIXTURE = 'framework/cache-fixture';

function cache_fixture(string $sub): string
{
    $dir = storage_path(CACHE_FIXTURE.'/'.$sub);
    remove_dir($dir);
    mkdir($dir, 0777, true);

    return $dir;
}

afterEach(function () {
    remove_dir(storage_path(CACHE_FIXTURE));
});

it('空缓存目录不触发任何重建', function () {
    Artisan::shouldReceive('call')->never();
    (new FrameworkCache(cache_fixture('empty')))->rebuild();
});

it('按存在文件精确重建对应命令', function () {
    $dir = cache_fixture('partial');
    file_put_contents($dir.'/config.php', '<?php return [];');
    file_put_contents($dir.'/routes-v1.php', '<?php return [];');
    Artisan::shouldReceive('call')->once()->with('config:cache');
    Artisan::shouldReceive('call')->once()->with('route:cache');
    Artisan::shouldReceive('call')->never()->with('event:cache');
    (new FrameworkCache($dir))->rebuild();
});

it('重建失败降级为告警不抛出', function () {
    $dir = cache_fixture('boom');
    file_put_contents($dir.'/config.php', '<?php return [];');
    Artisan::shouldReceive('call')->andThrow(new RuntimeException('disk full'));
    Log::shouldReceive('warning')->once()->withArgs(fn ($msg) => str_contains($msg, 'config:cache'));
    (new FrameworkCache($dir))->rebuild();    // 走到这里即未抛异常
    expect(true)->toBeTrue();
});
