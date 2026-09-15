<?php

use App\Support\Addon\AddonManager;
use App\Support\Addon\Models\Addon;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

it('Addons 前缀类可被自动加载到 addons/<key>/src', function () {
    app(AddonManager::class)->boot();
    expect(class_exists(\Addons\demo\AddonServiceProvider::class))->toBeTrue()
        ->and(class_exists(\Addons\demo\Addon::class))->toBeTrue()
        ->and(class_exists(\Addons\demo\Http\Controllers\NoteController::class))->toBeTrue();
});

it('启用插件的路由在引导时挂载到 /api/admin/addon/<key>', function () {
    Addon::create([
        'name' => 'demo', 'title' => '演示插件', 'version' => '0.1.0',
        'enabled' => true, 'install_time' => now(),
    ]);
    app(AddonManager::class)->boot();
    $route = app('router')->getRoutes()->match(
        Request::create('http://localhost/api/admin/addon/demo/notes')
    );
    expect($route->getControllerClass())->toBe(\Addons\demo\Http\Controllers\NoteController::class)
        ->and($route->getActionName())->toContain('index');
});

it('未启用插件的路由不挂载', function () {
    app(AddonManager::class)->boot();
    expect(fn () => app('router')->getRoutes()->match(
        Request::create('http://localhost/api/admin/addon/demo/notes')
    ))->toThrow(NotFoundHttpException::class);
});

it('boot 幂等：重复调用不重复注册 provider', function () {
    Addon::create([
        'name' => 'demo', 'title' => '演示插件', 'version' => '0.1.0',
        'enabled' => true, 'install_time' => now(),
    ]);
    app(AddonManager::class)->boot();
    $routesBefore = count(app('router')->getRoutes());
    app(AddonManager::class)->boot();
    expect(count(app('router')->getRoutes()))->toBe($routesBefore);
});
