<?php

use App\Admin\Models\Admin;
use App\Support\Addon\Models\Addon;
use App\Support\Setup\FrameworkSync;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    (new App\Admin\Seeds\RbacSeeder)->run();
    (new App\Admin\Seeds\MenuSeeder)->run();
});

afterEach(function () {
    app(App\Support\Addon\AddonManager::class)->flushCompiled();
});

it('权限被回收后 ark:sync 恢复超管全部权限（M6 踩坑回归）', function () {
    // 模拟事故：运维误从超管角色回收权限（spatie 里用户权限经角色继承，须从角色撤）
    Role::findByName(config('arkadmin.super_role'), 'admin')->revokePermissionTo('system.setting.update');
    expect(super_admin()->hasPermissionTo('system.setting.update'))->toBeFalse();

    $this->artisan('ark:sync')->assertExitCode(0);
    expect(super_admin()->hasPermissionTo('system.setting.update'))->toBeTrue()
        ->and(super_admin()->hasPermissionTo('system.admin.index'))->toBeTrue();
});

it('系统插件缺失时幂等跳过、已装时跳过，退出码不致命', function () {
    config(['arkadmin.system_addons' => ['settings', 'ghost_addon']]);
    $this->artisan('ark:sync')->assertExitCode(0);
    expect(Addon::find('settings'))->not->toBeNull();

    $result = app(FrameworkSync::class)->run();
    expect($result['installed'])->toBe([])
        ->and($result['skipped'])->toHaveKey('settings')
        ->and($result['skipped'])->toHaveKey('ghost_addon');
});

it('--no-addons 只同步权限与菜单', function () {
    config(['arkadmin.system_addons' => ['settings']]);
    $this->artisan('ark:sync', ['--no-addons' => true])->assertExitCode(0);
    expect(Addon::find('settings'))->toBeNull()
        ->and(Admin::where('username', 'admin')->exists())->toBeTrue();
});

it('db:seed 委托 FrameworkSync 行为一致', function () {
    config(['arkadmin.system_addons' => ['settings', 'op_logs']]);
    $this->artisan('db:seed', ['--class' => 'DatabaseSeeder', '--force' => true])->assertExitCode(0);
    expect(Addon::find('settings'))->not->toBeNull()
        ->and(Addon::find('op_logs'))->not->toBeNull();
});
