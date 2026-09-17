<?php

use App\Support\Addon\AddonDependency;
use App\Support\Addon\AddonException;
use App\Support\Addon\AddonInstaller;
use App\Support\Addon\AddonManager;
use App\Support\Addon\Models\Addon;

beforeEach(function () {
    config(['arkadmin.addon_path' => storage_path('framework/addon-fixture')]);
});

afterEach(function () {
    app(AddonManager::class)->flushCompiled();
    remove_dir(storage_path('framework/addon-fixture'));
});

/** dep（被依赖者）+ userb（依赖方）双 fixture，可选拆装 */
function make_dep_pair(bool $installUser = true, bool $enableUser = true): void
{
    make_addon_dir('depa');
    make_addon_dir('userb', ['dependencies' => ['depa']]);
    $installer = app(AddonInstaller::class);
    $installer->install('depa');
    if ($installUser) {
        $installer->install('userb');
        if (! $enableUser) {
            $installer->disable('userb');
        }
    }
}

it('安装：依赖已装但未启用也能装（启用态不要求）', function () {
    make_dep_pair(installUser: false);
    app(AddonInstaller::class)->install('userb');
    expect(Addon::find('userb'))->not->toBeNull();
});

it('启用：依赖未启用被拒绝，启用依赖后可启用', function () {
    make_dep_pair();
    $installer = app(AddonInstaller::class);
    // 反向保护要求依赖方先停，才能停被依赖者
    $installer->disable('userb');
    $installer->disable('depa');
    try {
        $installer->enable('userb');
        $this->fail('应当拒绝');
    } catch (AddonException $e) {
        expect($e->getMessage())->toContain('未安装或未启用');
    }
    $installer->enable('depa');
    $installer->enable('userb');
    expect(Addon::find('userb')->enabled)->toBeTrue();
});

it('环检测：互为依赖的清单在安装时被拒绝', function () {
    // 2-环无法靠顺序安装成立（互为前提），现实暴露路径是「先装无依赖版，再改清单引入依赖」：
    make_addon_dir('ringa');    // v1：无依赖，可正常安装
    make_addon_dir('ringb', ['dependencies' => ['ringa']]);
    $installer = app(AddonInstaller::class);
    $installer->install('ringa');
    // v2：ringa 的清单改成依赖 ringb（发布后改清单的常见事故）
    $info = json_decode((string) file_get_contents(storage_path('framework/addon-fixture/ringa').'/info.json'), true);
    $info['dependencies'] = ['ringb'];
    file_put_contents(storage_path('framework/addon-fixture/ringa').'/info.json', json_encode($info));

    try {
        $installer->install('ringb');   // ringa 已安装满足依赖，但 ringa→ringb→ringa 成环
        $this->fail('应当检出环');
    } catch (AddonException $e) {
        expect($e->getMessage())->toContain('依赖成环');
    }
    expect(Addon::find('ringb'))->toBeNull();
});

it('disable：被已启用插件依赖时拒绝，文案列出依赖方', function () {
    make_dep_pair();
    $installer = app(AddonInstaller::class);
    try {
        $installer->disable('depa');
        $this->fail('应当拒绝');
    } catch (AddonException $e) {
        expect($e->getMessage())->toContain('userb')->toContain('禁用');
    }
    expect(Addon::find('depa')->enabled)->toBeTrue();
});

it('uninstall：被禁用插件依赖同样拒绝（依赖方卸载后放行）', function () {
    make_dep_pair();
    $installer = app(AddonInstaller::class);
    // 卸载前置状态机：先停依赖方 userb，被依赖者 depa 才允许停（此时 userb 已禁用）
    $installer->disable('userb');
    $installer->disable('depa');
    try {
        $installer->uninstall('depa');
        $this->fail('应当拒绝');
    } catch (AddonException $e) {
        expect($e->getMessage())->toContain('userb')->toContain('卸载');
    }
    $installer->uninstall('userb');
    $installer->uninstall('depa');
    expect(Addon::find('depa'))->toBeNull();
});

it('无依赖者时行为不变（回归）', function () {
    make_addon_dir('lonely');
    $installer = app(AddonInstaller::class);
    $installer->install('lonely');
    $installer->disable('lonely');
    $installer->uninstall('lonely');
    expect(Addon::find('lonely'))->toBeNull()
        ->and(app(AddonDependency::class)->dependents('lonely'))->toBe([]);
});

it('自依赖被拒绝（自身尚未注册，先落在依赖未安装卡口）', function () {
    make_addon_dir('selfy', ['dependencies' => ['selfy']]);
    try {
        app(AddonInstaller::class)->install('selfy');
        $this->fail('应当拒绝');
    } catch (AddonException $e) {
        expect($e->getMessage())->toContain('selfy');
    }
    expect(Addon::find('selfy'))->toBeNull();
});
