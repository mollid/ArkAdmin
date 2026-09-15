<?php

use App\Support\Addon\AddonInstaller;
use App\Support\Addon\AddonManager;

beforeEach(function () {
    (new App\Admin\Seeds\RbacSeeder)->run();
    // 前端产物隔离到临时目录，避免测试污染真实 admin/src/addons
    config(['arkadmin.admin_path' => storage_path('framework/admin-fixture')]);
});

afterEach(function () {
    remove_dir(storage_path('framework/admin-fixture'));
});

/** 生成带前端目录的 fixture 插件：admin/views/x/index.vue + admin/lang/zh-cn.ts */
function make_frontend_addon(string $name): string
{
    $dir = make_addon_dir($name);
    foreach ([
        '/admin/views/x/index.vue' => '<template><div>x</div></template>',
        '/admin/lang/zh-cn.ts' => "export default { x: { title: 'X' } }\n",
    ] as $file => $content) {
        $path = $dir.$file;
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $content);
    }

    return $dir;
}

it('install 把插件 admin/ 复制到 admin/src/addons/<key>', function () {
    config(['arkadmin.addon_path' => storage_path('framework/addon-fixture')]);
    make_frontend_addon('fe');

    app(AddonInstaller::class)->install('fe');

    $frontend = config('arkadmin.admin_path').'/src/addons/fe';
    expect(is_file($frontend.'/views/x/index.vue'))->toBeTrue()
        ->and(is_file($frontend.'/lang/zh-cn.ts'))->toBeTrue()
        // 只复制 admin/ 子树，插件后端源码不得泄漏进前端目录
        ->and(is_dir($frontend.'/src'))->toBeFalse()
        ->and(is_file($frontend.'/info.json'))->toBeFalse();
});

it('install 提示构建命令（仅当插件带前端）', function () {
    config(['arkadmin.addon_path' => storage_path('framework/addon-fixture')]);
    make_frontend_addon('fe');

    $this->artisan('addon:install', ['name' => 'fe'])
        ->expectsOutputToContain('npm run build')
        ->assertExitCode(0);

    // 无 admin/ 目录的插件不提示（不误导纯后端插件使用者）
    make_addon_dir('beonly');
    $this->artisan('addon:install', ['name' => 'beonly'])
        ->doesntExpectOutputToContain('npm run build')
        ->assertExitCode(0);
});

it('重复 install 覆盖而非叠加上次残留', function () {
    config(['arkadmin.addon_path' => storage_path('framework/addon-fixture')]);
    make_frontend_addon('fe');
    $installer = app(AddonInstaller::class);
    $installer->install('fe');

    // 上次复制产物中塞一个脏文件，重装后应消失（先清后拷）
    $stale = config('arkadmin.admin_path').'/src/addons/fe/views/stale.vue';
    file_put_contents($stale, 'stale');
    $installer->disable('fe');
    $installer->uninstall('fe');
    $installer->install('fe');
    expect(is_file($stale))->toBeFalse()
        ->and(is_file(config('arkadmin.admin_path').'/src/addons/fe/views/x/index.vue'))->toBeTrue();
});

it('uninstall 删除前端产物', function () {
    config(['arkadmin.addon_path' => storage_path('framework/addon-fixture')]);
    make_frontend_addon('fe');
    $installer = app(AddonInstaller::class);
    $installer->install('fe');
    $installer->disable('fe');
    $installer->uninstall('fe');

    expect(is_dir(config('arkadmin.admin_path').'/src/addons/fe'))->toBeFalse();
});

it('keep-data 卸载同样删除前端产物', function () {
    config(['arkadmin.addon_path' => storage_path('framework/addon-fixture')]);
    make_frontend_addon('fe');
    $installer = app(AddonInstaller::class);
    $installer->install('fe');
    $installer->disable('fe');
    $installer->uninstall('fe', keepData: true);

    expect(is_dir(config('arkadmin.admin_path').'/src/addons/fe'))->toBeFalse();
});
