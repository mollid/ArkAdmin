<?php

use App\Admin\Models\Admin;
use App\Support\Addon\AddonInstaller;
use App\Support\Addon\AddonManager;
use App\Support\Addon\Models\Addon;

beforeEach(function () {
    (new App\Admin\Seeds\RbacSeeder)->run();
    (new App\Admin\Seeds\MenuSeeder)->run();
});

afterEach(function () {
    app(AddonManager::class)->flushCompiled();
});

it('列表：磁盘插件全量（含未安装）+ 注册表状态 + 可升级标记', function () {
    config(['arkadmin.addon_path' => storage_path('framework/addon-fixture')]);
    make_addon_dir('lista', ['version' => '0.2.0']);
    app(AddonInstaller::class)->install('lista');
    // install 后磁盘 bump 版本，模拟「待升级」：注册表停在 0.2.0，磁盘 0.3.0
    $file = storage_path('framework/addon-fixture/lista').'/info.json';
    $info = json_decode((string) file_get_contents($file), true);
    $info['version'] = '0.3.0';
    file_put_contents($file, json_encode($info, JSON_UNESCAPED_UNICODE));
    make_addon_dir('listb');    // 未安装

    $rows = $this->getJson('/api/admin/addons', ['Authorization' => 'Bearer '.admin_token()])
        ->assertOk()->assertJsonPath('code', 0)->json('data');
    $a = collect($rows)->firstWhere('name', 'lista');
    $b = collect($rows)->firstWhere('name', 'listb');
    expect($a['installed'])->toBeTrue()->and($a['enabled'])->toBeTrue()
        ->and($a['upgradable'])->toBeTrue()
        ->and($a['installed_version'])->toBe('0.2.0')->and($a['version'])->toBe('0.3.0')
        ->and($b['installed'])->toBeFalse()->and($b['upgradable'])->toBeFalse();
});

it('安装/启停/升级/卸载走通，带前端插件返回 needs_build', function () {
    // 同步目标须是合法后台目录（admin_path/src 存在），否则框架按配置无效拒绝同步
    @mkdir(storage_path('framework/admin-test/src'), 0777, true);
    $token = admin_token();
    $this->postJson('/api/admin/addons', ['name' => 'demo'], ['Authorization' => "Bearer {$token}"])
        ->assertOk()->assertJsonPath('code', 0)->assertJsonPath('data.needs_build', true);

    $this->putJson('/api/admin/addons/demo', ['action' => 'disable'], ['Authorization' => "Bearer {$token}"])
        ->assertOk()->assertJsonPath('code', 0);
    $this->putJson('/api/admin/addons/demo', ['action' => 'enable'], ['Authorization' => "Bearer {$token}"])
        ->assertOk()->assertJsonPath('code', 0);
    // 版本相同：upgraded=false
    $this->putJson('/api/admin/addons/demo', ['action' => 'upgrade'], ['Authorization' => "Bearer {$token}"])
        ->assertOk()->assertJsonPath('data.upgraded', false);
    // 未安装插件升级
    $this->putJson('/api/admin/addons/cms', ['action' => 'upgrade'], ['Authorization' => "Bearer {$token}"])
        ->assertOk()->assertJsonPath('code', 1);

    // 卸载前置状态机：upgrade 用例把 demo 重新启用过，先禁用
    $this->putJson('/api/admin/addons/demo', ['action' => 'disable'], ['Authorization' => "Bearer {$token}"])
        ->assertOk()->assertJsonPath('code', 0);
    $this->deleteJson('/api/admin/addons/demo?keep_data=1', [], ['Authorization' => "Bearer {$token}"])
        ->assertOk()->assertJsonPath('code', 0);
    expect(Addon::find('demo'))->toBeNull();
});

it('系统插件保护：disable/uninstall 在 HTTP 层被拒，enable 不受限', function () {
    $token = admin_token();
    app(AddonInstaller::class)->install('settings');

    $resp = $this->putJson('/api/admin/addons/settings', ['action' => 'disable'], ['Authorization' => "Bearer {$token}"]);
    $resp->assertOk()->assertJsonPath('code', 1);
    expect($resp->json('msg'))->toContain('系统插件')
        ->and(Addon::find('settings')->enabled)->toBeTrue();

    $resp = $this->deleteJson('/api/admin/addons/settings', [], ['Authorization' => "Bearer {$token}"]);
    $resp->assertOk()->assertJsonPath('code', 1);
    expect($resp->json('msg'))->toContain('系统插件')
        ->and(Addon::find('settings'))->not->toBeNull();

    // 非系统插件不受影响
    app(AddonInstaller::class)->install('demo');
    $this->putJson('/api/admin/addons/demo', ['action' => 'disable'], ['Authorization' => "Bearer {$token}"])
        ->assertOk()->assertJsonPath('code', 0);
});

it('参数校验：未知 action 422、未知插件安装 code 1', function () {
    $token = admin_token();
    $this->putJson('/api/admin/addons/demo', ['action' => 'explode'], ['Authorization' => "Bearer {$token}"])
        ->assertStatus(422);
    $this->postJson('/api/admin/addons', ['name' => 'ghost'], ['Authorization' => "Bearer {$token}"])
        ->assertOk()->assertJsonPath('code', 1);
});

// 403 用例必须让无权限账号发起本测试内首个带认证请求（Sanctum guard 测试进程缓存首个解析用户）
it('无 system.addon.* 权限返回 403 信封', function () {
    Admin::create(['username' => 'plain', 'password' => 'x123456', 'status' => 1]);
    $t2 = $this->postJson('/api/admin/auth/login', ['username' => 'plain', 'password' => 'x123456'])->json('data.token');

    $this->getJson('/api/admin/addons', ['Authorization' => "Bearer {$t2}"])->assertOk()->assertJsonPath('code', 403);
    $this->postJson('/api/admin/addons', ['name' => 'demo'], ['Authorization' => "Bearer {$t2}"])->assertOk()->assertJsonPath('code', 403);
});

it('AUDIT-D1 列表页依赖方反查一次完成（不再逐行 N+1）', function () {
    config(['arkadmin.addon_path' => storage_path('framework/addon-fixture')]);
    make_addon_dir('nplusa');
    make_addon_dir('nplusb', ['dependencies' => ['nplusa']]);
    make_addon_dir('nplusc', ['dependencies' => ['nplusa']]);
    $installer = app(AddonInstaller::class);
    $installer->install('nplusa');
    $installer->install('nplusb');
    $installer->install('nplusc');

    $addonQueries = 0;
    \Illuminate\Support\Facades\DB::listen(function ($query) use (&$addonQueries) {
        if (str_contains($query->sql, 'from "addons"')) {
            $addonQueries++;
        }
    });
    $rows = $this->getJson('/api/admin/addons', ['Authorization' => 'Bearer '.admin_token()])
        ->assertOk()->assertJsonPath('code', 0)->json('data');
    // 本文件早前用例的 fixture 残留磁盘，scan 全量 glob——按前缀过滤只断言本用例目标
    $nplus = collect($rows)->filter(fn ($r) => str_starts_with($r['name'], 'nplus'))->values();
    expect($nplus)->toHaveCount(3)
        ->and(collect($rows)->firstWhere('name', 'nplusa')['dependents'])->toBe(['nplusb', 'nplusc'])
        // Addon::all() + 依赖存在性预取 + dependentsMap 反查 = 恒定 3 次，与行数无关
        ->and($addonQueries)->toBeLessThanOrEqual(3);
});

it('非 AddonException 异常（插件钩子抛错）仍以 code 1 信封返回', function () {
    config(['arkadmin.addon_path' => storage_path('framework/addon-fixture')]);
    @mkdir(storage_path('framework/admin-test/src'), 0777, true);
    $dir = make_addon_dir('boom');
    make_fixture_installable($dir, 'boom');
    file_put_contents($dir.'/src/Addon.php', <<<'PHP'
<?php

namespace Addons\boom;

use App\Support\Addon\Contracts\Lifecycle;

class Addon implements Lifecycle
{
    public function install(): void
    {
        throw new \RuntimeException('boom from hook');
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

    public function upgrade(string $fromVersion): void
    {
    }
}
PHP);

    $resp = $this->postJson('/api/admin/addons', ['name' => 'boom'], ['Authorization' => 'Bearer '.admin_token()]);
    $resp->assertOk()->assertJsonPath('code', 1);
    expect($resp->json('msg'))->toContain('插件安装失败');
});
