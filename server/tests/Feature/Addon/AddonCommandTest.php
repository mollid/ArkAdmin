<?php

use App\Support\Addon\AddonInstaller;
use App\Support\Addon\AddonManager;
use App\Support\Addon\Models\Addon;

beforeEach(function () {
    (new App\Admin\Seeds\RbacSeeder)->run();
    (new App\Admin\Seeds\MenuSeeder)->run();
});

afterEach(function () {
    app(AddonManager::class)->flushCompiled();
    remove_dir(storage_path('framework/addon-fixture'));
});

it('addon:list 列出磁盘插件（含未安装）', function () {
    $this->artisan('addon:list')->assertExitCode(0);
});

it('安装不存在的插件失败', function () {
    $this->artisan('addon:install', ['name' => 'ghost'])->assertExitCode(1);
});

it('重复安装失败', function () {
    install_demo();
    $this->artisan('addon:install', ['name' => 'demo'])->assertExitCode(1);
});

it('未启用时执行 disable 失败', function () {
    install_demo();
    app(AddonInstaller::class)->disable('demo');
    $this->artisan('addon:disable', ['name' => 'demo'])->assertExitCode(1);
});

it('已启用时执行 enable 失败', function () {
    install_demo();
    $this->artisan('addon:enable', ['name' => 'demo'])->assertExitCode(1);
});

it('启用状态执行 uninstall 失败', function () {
    install_demo();
    $this->artisan('addon:uninstall', ['name' => 'demo'])->assertExitCode(1);
    expect(Addon::find('demo'))->not->toBeNull();
});

it('support_version 不满足拒绝安装且零副作用', function () {
    make_addon_dir('needhigh', ['support_version' => '99.0.0']);
    config(['arkadmin.addon_path' => storage_path('framework/addon-fixture')]);
    // 断言具体错误消息：确保拒绝发生在版本校验这一步，而非"目录不存在"之类的空转失败
    $this->artisan('addon:install', ['name' => 'needhigh'])
        ->expectsOutputToContain('框架版本')
        ->assertExitCode(1);
    expect(Addon::find('needhigh'))->toBeNull();
});

it('依赖未安装拒绝安装', function () {
    make_addon_dir('needdep', ['dependencies' => ['ghost']]);
    config(['arkadmin.addon_path' => storage_path('framework/addon-fixture')]);
    $this->artisan('addon:install', ['name' => 'needdep'])
        ->expectsOutputToContain('依赖插件')
        ->assertExitCode(1);
    expect(Addon::find('needdep'))->toBeNull();
});

it('命令走通的完整装/停/卸回路', function () {
    $this->artisan('addon:install', ['name' => 'demo'])->assertExitCode(0);
    expect(Addon::find('demo')->enabled)->toBeTrue();
    $this->artisan('addon:disable', ['name' => 'demo'])->assertExitCode(0);
    $this->artisan('addon:uninstall', ['name' => 'demo'])->assertExitCode(0);
    expect(Addon::find('demo'))->toBeNull();
});

it('AUDIT-C5a enable 重同步前端后提示构建命令（纯后端插件不提示）', function () {
    config(['arkadmin.addon_path' => storage_path('framework/addon-fixture')]);
    // admin_path 有效（src/ 存在）是前端同步的前提
    @mkdir(storage_path('framework/admin-test/src'), 0777, true);
    $dir = make_addon_dir('feen');
    @mkdir($dir.'/admin/views/x', 0777, true);
    file_put_contents($dir.'/admin/views/x/index.vue', '<template><div/></template>');
    $installer = app(AddonInstaller::class);
    $installer->install('feen');
    $installer->disable('feen');

    $this->artisan('addon:enable', ['name' => 'feen'])
        ->expectsOutputToContain('npm run build')
        ->assertExitCode(0);

    make_addon_dir('beonly2');
    $installer->install('beonly2');
    $installer->disable('beonly2');
    $this->artisan('addon:enable', ['name' => 'beonly2'])
        ->doesntExpectOutputToContain('npm run build')
        ->assertExitCode(0);
});
