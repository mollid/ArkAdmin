<?php

use App\Support\Addon\AddonInstaller;
use App\Support\Addon\AddonManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    (new App\Admin\Seeds\RbacSeeder)->run();
    (new App\Admin\Seeds\MenuSeeder)->run();
});

afterEach(function () {
    app(AddonManager::class)->flushCompiled();
});

it('登录成功触发 admin.login.successed 且插件监听写入业务表', function () {
    install_demo(); // 安装收尾即注册 provider，监听在本进程立即生效
    admin_token();  // 登录 → 事件 → demo 监听器写 demo_notes
    expect(DB::table('demo_notes')->where('content', 'login:admin')->count())->toBe(1);
});

it('卸载后登录不再写入且不报错', function () {
    $installer = app(AddonInstaller::class);
    $installer->install('demo');
    $installer->disable('demo'); // 进程内摘除监听
    $installer->uninstall('demo'); // 业务表回滚
    admin_token(); // 表已不存在，监听已摘除 → 登录必须正常
    expect(Schema::hasTable('demo_notes'))->toBeFalse();
});
