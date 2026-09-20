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

it('AUDIT-B6a 截断的编译缓存不再炸穿引导：自愈清除并回退注册表现算', function () {
    // 注册表直接登记 demo（不经 installer，保证 boot 是 demo 的唯一加载路径）
    App\Support\Addon\Models\Addon::create([
        'name' => 'demo', 'title' => '演示插件', 'version' => '0.1.0',
        'enabled' => true, 'install_time' => now(),
    ]);
    $manager = app(AddonManager::class);
    // 模拟磁盘满导致的半写文件（无闭合引号/分号）
    file_put_contents($manager->compiledFile(), "<?php\n\nreturn ['demo' => ['name' => 'dem");
    $manager->boot(); // 修复前：ParseError 穿透引导，连 addon:clear 都起不来
    expect($manager->isCompiled())->toBeFalse(); // 坏缓存已被清除（自愈）
    expect(fn () => app('router')->getRoutes()->match(
        \Illuminate\Http\Request::create('http://localhost/api/admin/addon/demo/notes')
    ))->not->toThrow(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class); // 回退注册表后 demo 正常加载
});

it('AUDIT-B6b 形状非法的编译缓存（return null / 0 字节）同样自愈不静默丢插件', function () {
    App\Support\Addon\Models\Addon::create([
        'name' => 'demo', 'title' => '演示插件', 'version' => '0.1.0',
        'enabled' => true, 'install_time' => now(),
    ]);
    $manager = app(AddonManager::class);
    foreach (["<?php\n\nreturn null;\n", ''] as $payload) {
        file_put_contents($manager->compiledFile(), $payload);
        $manager->boot(); // 修复前：foreach 非数组仅告警，全部插件静默不加载且不回退
        expect($manager->isCompiled())->toBeFalse();
        expect(fn () => app('router')->getRoutes()->match(
            \Illuminate\Http\Request::create('http://localhost/api/admin/addon/demo/notes')
        ))->not->toThrow(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
    }
});

it('AUDIT-B5a compile 原子替换：写失败不落半写文件且无临时残留', function () {
    install_demo();
    $manager = app(AddonManager::class);
    $file = $manager->compiledFile();
    mkdir($file); // 目标路径被目录占位 → rename 必败，等价磁盘满/权限异常的写失败
    try {
        $manager->compile();
        $this->fail('写失败应当抛出');
    } catch (App\Support\Addon\AddonException $e) {
        expect($e->getMessage())->toContain('写入失败');
    } finally {
        rmdir($file);
    }
    expect($manager->isCompiled())->toBeFalse();
    expect(glob($file.'.tmp-*'))->toBeEmpty(); // 失败路径不残留 .tmp
    // 成功路径同样不留临时文件
    $manager->compile();
    expect($manager->isCompiled())->toBeTrue();
    expect(glob($file.'.tmp-*'))->toBeEmpty();
});

it('AUDIT-C5b addon:cache 写失败如实报错退出非零（不再假成功）', function () {
    install_demo();
    $manager = app(AddonManager::class);
    mkdir($manager->compiledFile());
    try {
        $this->artisan('addon:cache')->assertExitCode(1);
    } finally {
        rmdir($manager->compiledFile());
    }
    expect($manager->isCompiled())->toBeFalse();
});

it('AUDIT-B5b flushCompiled 清除失败如实返回 false，addon:clear 不谎报成功', function () {
    install_demo();
    $manager = app(AddonManager::class);
    $manager->compile();
    $dir = dirname($manager->compiledFile());
    $origPerms = fileperms($dir) & 0777;
    chmod($dir, 0555); // 目录只读 → unlink 必败
    try {
        expect($manager->flushCompiled())->toBeFalse();
        expect($manager->isCompiled())->toBeTrue();
        $this->artisan('addon:clear')->assertExitCode(1);
    } finally {
        chmod($dir, $origPerms);
    }
    expect($manager->flushCompiled())->toBeTrue();
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
