<?php

use App\Support\Addon\AddonInstaller;
use App\Support\Addon\AddonManager;

beforeEach(function () {
    (new App\Admin\Seeds\RbacSeeder)->run();
    (new App\Admin\Seeds\MenuSeeder)->run();
});

afterEach(function () {
    // 测试进程写出的编译缓存必须清掉，避免污染同机开发运行时
    app(AddonManager::class)->flushCompiled();
});

it('addon:cache 生成含 provider 与监听映射的编译缓存', function () {
    install_demo();
    $manager = app(AddonManager::class);
    expect($manager->isCompiled())->toBeFalse();
    $this->artisan('addon:cache')->assertExitCode(0);
    expect($manager->isCompiled())->toBeTrue();

    $map = require $manager->compiledFile();
    expect($map['demo']['provider'])->toBe('Addons\demo\AddonServiceProvider')
        ->and($map['demo']['dir'])->toBe(base_path('addons/demo'))
        ->and(array_key_exists('listeners', $map['demo']))->toBeFalse();
});

it('任一生命周期变更都会冲掉编译缓存（下次 boot 走注册表现算）', function () {
    install_demo();
    $manager = app(AddonManager::class);
    $manager->compile();
    expect($manager->isCompiled())->toBeTrue();
    app(AddonInstaller::class)->disable('demo');
    expect($manager->isCompiled())->toBeFalse();
});

it('addon:clear 清除缓存', function () {
    install_demo();
    $manager = app(AddonManager::class);
    $manager->compile();
    $this->artisan('addon:clear')->assertExitCode(0);
    expect($manager->isCompiled())->toBeFalse();
});

it('缓存指向的插件目录失效时 boot 降级为跳过且不致命', function () {
    // 注册表启用 demo 但不走 installer（避免其进程内即时注册 provider），
    // 让编译缓存成为本进程唯一的插件加载来源，才能断言缓存内容的取舍
    App\Support\Addon\Models\Addon::create([
        'name' => 'demo', 'title' => '演示插件', 'version' => '0.1.0',
        'enabled' => true, 'install_time' => now(),
    ]);
    $manager = app(AddonManager::class);
    // 模拟缓存过期（记录的 dir 不存在，且真实启用中的 demo 不在缓存里）
    file_put_contents($manager->compiledFile(), "<?php\n\nreturn ".var_export([
        'ghost' => ['name' => 'ghost', 'dir' => '/nonexistent/addons/ghost'],
    ], true).";\n");
    // boot 不抛异常（loadableInfos 对坏目录记日志跳过）；
    // 缓存启用时不回源注册表——demo 未从缓存加载，这正是生命周期变更必须冲缓存的语义
    $manager->boot();
    expect(fn () => app('router')->getRoutes()->match(
        \Illuminate\Http\Request::create('http://localhost/api/admin/addon/demo/notes')
    ))->toThrow(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
});
