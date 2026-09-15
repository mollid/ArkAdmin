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
        ->and($map['demo']['listeners'])->toBeArray();
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
    install_demo();
    $manager = app(AddonManager::class);
    $manager->compile();
    // 模拟缓存过期（记录的 dir 不存在）
    file_put_contents($manager->compiledFile(), "<?php\n\nreturn ".var_export([
        'ghost' => ['name' => 'ghost', 'dir' => '/nonexistent/addons/ghost'],
    ], true).";\n");
    // boot 不抛异常（loadableInfos 对坏目录记日志跳过）
    $manager->boot();
    expect(true)->toBeTrue();
});
