<?php

use App\Admin\Models\Admin;
use App\Admin\Models\Menu;
use App\Admin\Services\MenuService;
use App\Support\Addon\AddonException;
use App\Support\Addon\AddonInstaller;
use App\Support\Addon\AddonManager;
use App\Support\Addon\Models\Addon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    (new App\Admin\Seeds\RbacSeeder)->run();
    (new App\Admin\Seeds\MenuSeeder)->run();
});

afterEach(function () {
    app(AddonManager::class)->flushCompiled();
});

it('测试环境把插件前端产物重定向到 storage，绝不指向真实 admin/', function () {
    // M3 评审轮回归：曾因未隔离导致跑测试删掉开发者工作区里的 admin/src/addons/<key>
    expect(config('arkadmin.admin_path'))->toStartWith(storage_path())
        ->and(config('arkadmin.admin_path'))->not->toBe(dirname(base_path()).'/admin');
});

it('安装 demo：注册表/业务表/菜单/权限齐备且接口可用', function () {
    $record = app(AddonInstaller::class)->install('demo');
    expect($record->enabled)->toBeTrue()
        ->and(Schema::hasTable('demo_notes'))->toBeTrue();

    $menuNames = Menu::where('addon_key', 'demo')->pluck('name');
    expect($menuNames)->toContain('demo', 'demo.note')
        ->and(Menu::where('name', 'demo.note')->value('view_path'))->toBe('note/index')
        ->and(Permission::where('module', 'demo')->pluck('name'))
        ->toContain('addon.demo.note.index', 'addon.demo.note.store');

    // RbacSeeder 语义（超管=全部权限）必须在插件安装时延续：新权限 sync 给 super_admin，
    // 否则前端 has() 字符串检查看不到新权限、按钮级权限失效（接口有 Gate::before 旁路不受影响）
    $super = \Spatie\Permission\Models\Role::where('name', 'super_admin')
        ->where('guard_name', 'admin')->first();
    expect($super->hasPermissionTo('addon.demo.note.store'))->toBeTrue();

    $token = admin_token(); // 登录事件本身会写入一条 login:admin 便签
    $this->getJson('/api/admin/addon/demo/notes', ['Authorization' => "Bearer {$token}"])
        ->assertOk()->assertJsonPath('code', 0);
    $this->postJson('/api/admin/addon/demo/notes', ['content' => 'hello'],
        ['Authorization' => "Bearer {$token}"])
        ->assertOk()->assertJsonPath('code', 0);
    expect(DB::table('demo_notes')->count())->toBe(2);
});

it('超管菜单树出现插件菜单，无权限管理员看不到', function () {
    app(AddonInstaller::class)->install('demo');
    expect(menu_tree_names((new MenuService)->treeFor(super_admin())))->toContain('demo', 'demo.note');

    $u = Admin::create(['username' => 'noperm2', 'password' => 'x123456', 'status' => 1]);
    expect(menu_tree_names((new MenuService)->treeFor($u)))->not->toContain('demo');
});

it('无权限管理员调用插件接口返回 403 信封', function () {
    app(AddonInstaller::class)->install('demo');
    $u = Admin::create(['username' => 'plain', 'password' => 'x123456', 'status' => 1]);
    $token = $this->postJson('/api/admin/auth/login', ['username' => 'plain', 'password' => 'x123456'])
        ->json('data.token');
    $this->getJson('/api/admin/addon/demo/notes', ['Authorization' => "Bearer {$token}"])
        ->assertOk()->assertJsonPath('code', 403);
});

it('disable：菜单不渲染但行保留、路由 404、业务表保留', function () {
    app(AddonInstaller::class)->install('demo');
    app(AddonInstaller::class)->disable('demo');
    expect(Addon::find('demo')->enabled)->toBeFalse()
        ->and(Menu::where('addon_key', 'demo')->count())->toBe(2)
        ->and(menu_tree_names((new MenuService)->treeFor(super_admin())))->not->toContain('demo')
        ->and(Schema::hasTable('demo_notes'))->toBeTrue();

    remount_routes();
    $token = admin_token();
    $this->getJson('/api/admin/addon/demo/notes', ['Authorization' => "Bearer {$token}"])
        ->assertOk()->assertJsonPath('code', 404);
});

it('enable：路由与菜单完整恢复', function () {
    $installer = app(AddonInstaller::class);
    $installer->install('demo');
    $installer->disable('demo');
    $installer->enable('demo');
    expect(menu_tree_names((new MenuService)->treeFor(super_admin())))->toContain('demo');

    remount_routes();
    $token = admin_token();
    $this->getJson('/api/admin/addon/demo/notes', ['Authorization' => "Bearer {$token}"])
        ->assertOk()->assertJsonPath('code', 0);
});

it('状态机：启用时禁止卸载/重复启用，禁用时禁止再禁用', function () {
    $installer = app(AddonInstaller::class);
    $installer->install('demo');
    expect(fn () => $installer->uninstall('demo'))->toThrow(AddonException::class)
        ->and(fn () => $installer->enable('demo'))->toThrow(AddonException::class);
    $installer->disable('demo');
    expect(fn () => $installer->disable('demo'))->toThrow(AddonException::class);
});

it('卸载：注册行/菜单/权限清除且业务表回滚', function () {
    $installer = app(AddonInstaller::class);
    $installer->install('demo');
    $installer->disable('demo');
    $installer->uninstall('demo');
    expect(Addon::find('demo'))->toBeNull()
        ->and(Menu::where('addon_key', 'demo')->count())->toBe(0)
        ->and(Permission::where('module', 'demo')->count())->toBe(0)
        ->and(Schema::hasTable('demo_notes'))->toBeFalse();
});

it('--keep-data 卸载保留业务表', function () {
    $installer = app(AddonInstaller::class);
    $installer->install('demo');
    $token = admin_token();
    $this->postJson('/api/admin/addon/demo/notes', ['content' => '保留我'],
        ['Authorization' => "Bearer {$token}"])->assertOk();
    $installer->disable('demo');
    $installer->uninstall('demo', keepData: true);
    expect(Schema::hasTable('demo_notes'))->toBeTrue()
        ->and(DB::table('demo_notes')->where('content', '保留我')->exists())->toBeTrue()
        ->and(Addon::find('demo'))->toBeNull()
        ->and(Menu::where('addon_key', 'demo')->count())->toBe(0);
});

it('卸载后可重新安装', function () {
    $installer = app(AddonInstaller::class);
    $installer->install('demo');
    $installer->disable('demo');
    $installer->uninstall('demo');
    $installer->install('demo');
    expect(Addon::find('demo')->enabled)->toBeTrue()
        ->and(Schema::hasTable('demo_notes'))->toBeTrue()
        ->and(Menu::where('addon_key', 'demo')->count())->toBe(2);
});
