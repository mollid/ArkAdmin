<?php

use App\Support\Addon\AddonException;
use App\Support\Addon\AddonInstaller;
use App\Support\Addon\AddonManager;
use App\Support\Addon\Models\Addon;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    config(['arkadmin.addon_path' => storage_path('framework/addon-fixture')]);
    (new App\Admin\Seeds\RbacSeeder)->run();
    (new App\Admin\Seeds\MenuSeeder)->run();
});

afterEach(function () {
    app(AddonManager::class)->flushCompiled();
    remove_dir(storage_path('framework/addon-fixture'));
});

function upgrade_log(): string
{
    return storage_path('framework/addon-fixture/upgrade.log');
}

function upx_migration(string $file, string $table): void
{
    file_put_contents($file, <<<PHP
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('{$table}', function (Blueprint \$table) {
            \$table->id();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('{$table}');
    }
};
PHP);
}

function upx_menus(): string
{
    return <<<'PHP'
<?php

return [
    ['name' => 'upx', 'title' => 'UP', 'icon' => 'Box', 'route_path' => '/upx',
        'view_path' => 'missing/index', 'permission' => 'addon.upx.index', 'sort' => 300],
];
PHP;
}

/** 可升级 fixture：迁移 + 菜单/权限 + upgrade() 钩子记录 fromVersion */
function make_upgradable_addon(string $version): void
{
    $dir = make_addon_dir('upx', ['version' => $version]);
    @mkdir($dir.'/database/migrations', 0777, true);
    upx_migration($dir.'/database/migrations/2026_09_15_000001_create_upx_things_table.php', 'upx_things');
    file_put_contents($dir.'/database/menus.php', upx_menus());
    file_put_contents($dir.'/database/permissions.php', "<?php\n\nreturn ['addon.upx.export'];\n");
    make_fixture_installable($dir, 'upx');
    // 重写 Addon.php：upgrade 钩子记录 fromVersion（默认实现为空，无法断言调用链）
    $log = upgrade_log();
    file_put_contents($dir.'/src/Addon.php', <<<PHP
<?php

namespace Addons\\upx;

use App\\Support\\Addon\\Contracts\\Lifecycle;

class Addon implements Lifecycle
{
    public function install(): void
    {
    }

    public function uninstall(): void
    {
    }

    public function enable(): void
    {
    }

    public function disable(): void
    {
    }

    public function upgrade(string \$fromVersion): void
    {
        file_put_contents('{$log}', \$fromVersion);
    }
}
PHP);
}

/** 升级到新版本：bump version + 追加第二张表迁移 + 追加菜单/权限 */
function upgrade_upx_fixture(string $to = '0.2.0'): void
{
    $dir = storage_path('framework/addon-fixture/upx');
    $info = json_decode((string) file_get_contents($dir.'/info.json'), true);
    $info['version'] = $to;
    file_put_contents($dir.'/info.json', json_encode($info, JSON_UNESCAPED_UNICODE));
    upx_migration($dir.'/database/migrations/2026_09_16_000001_create_upx_extra_table.php', 'upx_extra');
    file_put_contents($dir.'/database/menus.php', str_replace(
        "];",
        "    ['name' => 'upx.extra', 'title' => 'UP2', 'icon' => 'Box', 'route_path' => '/upx/extra',\n"
        ."        'view_path' => 'missing/index', 'permission' => 'addon.upx.extra', 'sort' => 301],\n];",
        upx_menus()
    ));
}

it('升级：新增迁移执行、钩子收到旧版本、菜单/权限补齐、注册表写回', function () {
    make_upgradable_addon('0.1.0');
    $installer = app(AddonInstaller::class);
    $installer->install('upx');
    expect(Schema::hasTable('upx_things'))->toBeTrue()
        ->and(Schema::hasTable('upx_extra'))->toBeFalse()
        ->and(Addon::find('upx')->version)->toBe('0.1.0');

    upgrade_upx_fixture();
    $info = $installer->upgrade('upx');

    expect($info?->version)->toBe('0.2.0')
        ->and(Schema::hasTable('upx_extra'))->toBeTrue()
        ->and(Addon::find('upx')->version)->toBe('0.2.0')
        ->and(Addon::find('upx')->enabled)->toBeTrue()
        ->and(file_get_contents(upgrade_log()))->toBe('0.1.0')
        ->and(\Spatie\Permission\Models\Permission::where('name', 'addon.upx.extra')->exists())->toBeTrue()
        ->and(\App\Admin\Models\Menu::where('name', 'upx.extra')->exists())->toBeTrue();
});

it('版本未变不执行；force 无条件补跑且不改版本号', function () {
    make_upgradable_addon('0.1.0');
    $installer = app(AddonInstaller::class);
    $installer->install('upx');
    // 忘 bump version 只改了迁移文件（迁移盲区典型现场）
    upx_migration(
        storage_path('framework/addon-fixture/upx').'/database/migrations/2026_09_16_000001_create_upx_extra_table.php',
        'upx_extra'
    );

    expect($installer->upgrade('upx'))->toBeNull()
        ->and(Schema::hasTable('upx_extra'))->toBeFalse();

    $installer->upgrade('upx', force: true);
    expect(Schema::hasTable('upx_extra'))->toBeTrue()
        ->and(Addon::find('upx')->version)->toBe('0.1.0');
});

it('未安装 / 框架版本不满足时报错', function () {
    make_upgradable_addon('0.1.0');
    $installer = app(AddonInstaller::class);
    try {
        $installer->upgrade('upx');
        $this->fail('应当拒绝');
    } catch (AddonException $e) {
        expect($e->getMessage())->toContain('未安装');
    }

    $installer->install('upx');
    $file = storage_path('framework/addon-fixture/upx').'/info.json';
    $info = json_decode((string) file_get_contents($file), true);
    $info['support_version'] = '99.0.0';
    $info['version'] = '0.2.0';     // 有可升级内容时才走到框架版本校验
    file_put_contents($file, json_encode($info));
    try {
        $installer->upgrade('upx');
        $this->fail('应当拒绝');
    } catch (AddonException $e) {
        expect($e->getMessage())->toContain('框架版本');
    }
});

it('addon:upgrade 命令：单插件与 --all 扫描', function () {
    make_upgradable_addon('0.1.0');
    app(AddonInstaller::class)->install('upx');
    upgrade_upx_fixture();

    $this->artisan('addon:upgrade', ['name' => 'upx'])->assertExitCode(0);
    expect(Addon::find('upx')->version)->toBe('0.2.0');

    upgrade_upx_fixture('0.3.0');
    $this->artisan('addon:upgrade', ['--all' => true])->assertExitCode(0);
    expect(Addon::find('upx')->version)->toBe('0.3.0');
});

it('addon:upgrade 同版本输出已是最新并零副作用', function () {
    make_upgradable_addon('0.1.0');
    app(AddonInstaller::class)->install('upx');
    file_put_contents(upgrade_log(), 'none');

    $this->artisan('addon:upgrade', ['name' => 'upx'])
        ->expectsOutputToContain('已是最新')
        ->assertExitCode(0);
    expect(file_get_contents(upgrade_log()))->toBe('none');
});

