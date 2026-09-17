<?php

use Addons\op_logs\Console\PruneOpLogs;
use App\Admin\Models\Menu;
use App\Admin\Models\Setting;
use App\Admin\Seeds\MenuSeeder;
use App\Admin\Seeds\RbacSeeder;
use App\Admin\Services\MenuService;
use App\Support\Addon\AddonInstaller;
use App\Support\Settings\SettingStore;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    (new RbacSeeder)->run();
    (new MenuSeeder)->run();
    // 系统插件在仓库 addons/ 下，真实安装（不经 fixture）
    config(['arkadmin.addon_path' => base_path('addons')]);
});

it('settings 系统插件：安装后 schema 进核心 API、菜单与权限就位', function () {
    app(AddonInstaller::class)->install('settings');

    $rows = $this->withToken(admin_token())->getJson('/api/admin/settings')
        ->assertOk()->assertJsonPath('code', 0)->json('data');
    expect(collect($rows)->pluck('key'))->toContain('site.name', 'site.description')
        ->and(menu_tree_names((new MenuService)->treeFor(super_admin())))->toContain('settings.manage')
        ->and(super_admin()->hasPermissionTo('addon.settings.manage'))->toBeTrue();
});

it('op_logs 系统插件：埋点自动落库、查询 API、widget 下发', function () {
    app(AddonInstaller::class)->install('op_logs');

    $token = admin_token();   // 登录成功 → 日志行
    $this->withToken($token)->postJson('/api/admin/admins', [
        'username' => 'u9', 'password' => 'x123456', 'status' => 1,
    ])->assertOk();

    $rows = $this->withToken($token)->getJson('/api/admin/addon/op_logs/logs')->assertOk()->json('data');
    expect($rows['total'])->toBeGreaterThanOrEqual(2);   // 登录成功 + 创建管理员

    $login = collect($rows['list'])->firstWhere('route', 'api/admin/auth/login');
    expect($login['admin_id'])->toBe(super_admin()->id)
        ->and($login['params']['password'])->toBe('***');   // 脱敏落库

    $widgets = $this->withToken($token)->getJson('/api/admin/widgets')->json('data');
    expect(array_column($widgets, 'key'))->toContain('op_logs.today');

    $today = $this->withToken($token)->getJson('/api/admin/addon/op_logs/today')->json('data');
    expect($today['operations'])->toBeGreaterThanOrEqual(1);
});

it('op_logs 禁用后埋点事件照发但无存储且不炸', function () {
    $installer = app(AddonInstaller::class);
    $installer->install('op_logs');
    admin_token();
    $before = DB::table('admin_op_logs')->count();

    $installer->disable('op_logs');   // 监听器被摘除
    admin_token();

    expect(DB::table('admin_op_logs')->count())->toBe($before);
});

it('卸载回路：插件资产清除，核心 settings 基建不受影响，重装恢复', function () {
    $installer = app(AddonInstaller::class);
    $installer->install('settings');
    $installer->install('op_logs');
    app(SettingStore::class)->set('site.name', '保留我', 'system');

    $installer->disable('settings');
    $installer->uninstall('settings');
    $installer->disable('op_logs');
    $installer->uninstall('op_logs');

    expect(Menu::where('addon_key', 'settings')->count())->toBe(0)
        ->and(Menu::where('addon_key', 'op_logs')->count())->toBe(0)
        ->and(Schema::hasTable('admin_op_logs'))->toBeFalse()
        // settings 基建在核心：表与系统共用键不受插件卸载影响
        ->and(Schema::hasTable('settings'))->toBeTrue()
        ->and(Setting::where('key', 'site.name')->value('addon_key'))->toBe('system')
        ->and(Permission::where('module', 'settings')->count())->toBe(0)
        ->and(Permission::where('module', 'op_logs')->count())->toBe(0);

    $installer->install('settings');
    $installer->install('op_logs');
    expect(menu_tree_names((new MenuService)->treeFor(super_admin())))
        ->toContain('settings.manage', 'op_logs.log');
});

it('op-logs:prune 清理超期日志', function () {
    app(AddonInstaller::class)->install('op_logs');
    // 运行时安装的 provider 赶不上 Artisan 启动时序，测试内显式注册命令
    // （CLI 全新进程里由 provider boot 的 commands() 正常注册）
    app(Kernel::class)->registerCommand(new PruneOpLogs);
    DB::table('admin_op_logs')->insert([
        'route' => 'old', 'method' => 'POST', 'params' => '[]', 'status_code' => 200,
        'duration_ms' => 0, 'ip' => '127.0.0.1', 'created_at' => now()->subDays(100),
    ]);

    $this->artisan('op-logs:prune')->assertExitCode(0);
    expect(DB::table('admin_op_logs')->where('route', 'old')->count())->toBe(0);
});
