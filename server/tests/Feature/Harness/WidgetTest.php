<?php

use App\Admin\Models\Admin;
use App\Admin\Seeds\RbacSeeder;
use App\Support\Addon\AddonInstaller;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    (new RbacSeeder)->run();
    config(['arkadmin.addon_path' => storage_path('framework/addon-fixture')]);

    $dir = make_addon_dir('fx');
    @mkdir($dir.'/database', 0777, true);
    file_put_contents($dir.'/database/widgets.php', <<<'PHP'
    <?php

    return [
        ['key' => 'fx.public', 'component' => 'public', 'title' => '公共卡'],
        ['key' => 'fx.today', 'component' => 'today', 'title' => '今日动态',
         'permission' => 'addon.fx.today', 'sort' => 5],
        ['key' => 'fx.bad'],   // 非法条目：跳过不炸
    ];

    PHP);
    app(AddonInstaller::class)->install('fx');
});

it('GET /widgets：启用插件声明下发、按 sort 排序、权限过滤、非法条目跳过', function () {
    $widgets = $this->withToken(admin_token())->getJson('/api/admin/widgets')
        ->assertOk()->assertJsonPath('code', 0)->json('data');

    expect(count($widgets))->toBe(2)   // fx.bad 被跳过
        ->and(array_column($widgets, 'key'))->toBe(['fx.public', 'fx.today'])   // sort 升序：0 → 5
        ->and($widgets[0]['addon'])->toBe('fx')
        ->and($widgets[0]['component'])->toBe('public');
});

it('无权限账号只看到未声明权限的 widget', function () {
    Admin::create(['username' => 'plain', 'password' => 'x123456', 'status' => 1]);
    $token = $this->postJson('/api/admin/auth/login', ['username' => 'plain', 'password' => 'x123456'])->json('data.token');

    $widgets = $this->getJson('/api/admin/widgets', ['Authorization' => "Bearer {$token}"])->json('data');
    expect(array_column($widgets, 'key'))->toBe(['fx.public']);
});

it('插件禁用后 widget 不再下发', function () {
    app(AddonInstaller::class)->disable('fx');

    $widgets = $this->withToken(admin_token())->getJson('/api/admin/widgets')->json('data');
    expect($widgets)->toBe([]);
});

it('卸载后 widgets 声明随插件消失且可重装恢复', function () {
    $installer = app(AddonInstaller::class);
    $installer->disable('fx');
    $installer->uninstall('fx');
    expect(Schema::hasTable('fx_dummy'))->toBeFalse();

    $installer->install('fx');
    $widgets = $this->withToken(admin_token())->getJson('/api/admin/widgets')->json('data');
    expect(count($widgets))->toBe(2);
});
