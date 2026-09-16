<?php

use App\Support\Addon\AddonInstaller;

beforeEach(function () {
    (new App\Admin\Seeds\RbacSeeder)->run();
    // 前端产物隔离到临时目录，避免测试污染真实 admin/src/addons；
    // 目录形状需具备 src/（syncFrontend 以此判定 admin_path 有效）
    config(['arkadmin.admin_path' => storage_path('framework/admin-fixture')]);
    @mkdir(storage_path('framework/admin-fixture/src'), 0777, true);
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
    $installer->disable('fe');
    $installer->uninstall('fe'); // 此时目标目录已被清掉

    // 手工重建带脏文件的目标目录：证明 install 内部确实先清后拷（而非沿用残留）
    $dest = $installer->frontendDir('fe');
    mkdir($dest.'/views', 0777, true);
    file_put_contents($dest.'/views/stale.vue', 'stale');

    $installer->install('fe');
    expect(is_file($dest.'/views/stale.vue'))->toBeFalse()
        ->and(is_file($dest.'/views/x/index.vue'))->toBeTrue();
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

it('目标目录不可写时不谎报成功：无同步提示、旧副本完整保留', function () {
    config(['arkadmin.addon_path' => storage_path('framework/addon-fixture')]);
    make_frontend_addon('fe');

    // 先成功安装一次，制造一份“好的旧副本”
    $installer = app(AddonInstaller::class);
    $installer->install('fe');
    $dest = $installer->frontendDir('fe');
    $installer->disable('fe');
    $installer->uninstall('fe');

    // 手工重建副本，并把 addons 父目录设为只读，模拟运行期不可写
    mkdir($dest.'/views/x', 0777, true);
    file_put_contents($dest.'/views/x/index.vue', 'old-copy');
    $addonsDir = dirname($dest);
    chmod($addonsDir, 0555);

    try {
        $this->artisan('addon:install', ['name' => 'fe'])
            ->doesntExpectOutputToContain('npm run build') // 同步失败 → 不打印构建提示
            ->assertExitCode(0);                          // 后端安装本身仍成功
        // 旧副本内容完好（原子替换：失败不破坏已有产物），且未记录为已同步
        expect(file_get_contents($dest.'/views/x/index.vue'))->toBe('old-copy')
            ->and($installer->lastSyncedFrontend)->toBeNull();
    } finally {
        chmod($addonsDir, 0777);
    }
});

it('admin_path 为空视为配置错误并拒绝同步', function () {
    config(['arkadmin.addon_path' => storage_path('framework/addon-fixture')]);
    config(['arkadmin.admin_path' => '  ']);
    make_frontend_addon('fe');

    $installer = app(AddonInstaller::class);
    $installer->install('fe');
    // 不抛异常（后端安装继续），但同步返回 null，不会往 filesystem 根写 /src/addons
    expect($installer->lastSyncedFrontend)->toBeNull()
        ->and(is_dir('/src/addons'))->toBeFalse();
});
