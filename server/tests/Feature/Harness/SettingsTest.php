<?php

use App\Admin\Models\Admin;
use App\Admin\Models\Setting;
use App\Admin\Seeds\RbacSeeder;
use App\Support\Addon\AddonInstaller;
use App\Support\Settings\SettingException;
use App\Support\Settings\SettingStore;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    (new RbacSeeder)->run();
    config(['arkadmin.addon_path' => storage_path('framework/addon-fixture')]);

    // fixture 插件：声明 2 个自有键 + 1 个系统共用键
    $dir = make_addon_dir('fx');
    @mkdir($dir.'/database', 0777, true);
    file_put_contents($dir.'/database/settings.php', <<<'PHP'
    <?php

    return [
        ['key' => 'fx.greeting', 'label' => '问候语', 'type' => 'text', 'default' => 'hello'],
        ['key' => 'fx.limit', 'label' => '上限', 'type' => 'number', 'default' => 10],
        ['key' => 'site.name', 'label' => '站点名称', 'type' => 'text', 'default' => 'ArkAdmin', 'scope' => 'system'],
    ];

    PHP);
    app(AddonInstaller::class)->install('fx');
});

it('GET /settings：schema 合并当前值（无值回退默认）', function () {
    $rows = $this->withToken(admin_token())->getJson('/api/admin/settings')
        ->assertOk()->assertJsonPath('code', 0)->json('data');

    $byKey = collect($rows)->keyBy('key');
    expect($byKey['fx.greeting']['value'])->toBe('hello')
        ->and($byKey['fx.greeting']['addon'])->toBe('fx')
        ->and($byKey['fx.greeting']['scope'])->toBe('plugin')
        ->and($byKey['site.name']['scope'])->toBe('system')
        ->and($byKey['site.name']['value'])->toBe('ArkAdmin');
});

it('PUT /settings：命中 schema 白名单落库，归属正确，GET 回读', function () {
    $this->withToken(admin_token())->putJson('/api/admin/settings', [
        'values' => ['fx.greeting' => 'hi', 'site.name' => '新名字', 'fx.limit' => 20],
    ])->assertOk()->assertJsonPath('code', 0);

    $rows = $this->withToken(admin_token())->getJson('/api/admin/settings')->json('data');
    $byKey = collect($rows)->keyBy('key');
    expect($byKey['fx.greeting']['value'])->toBe('hi')
        ->and($byKey['fx.limit']['value'])->toBe(20)
        ->and($byKey['site.name']['value'])->toBe('新名字');

    // 归属落库：插件键 addon_key=fx，系统键 addon_key=system
    expect(Setting::where('key', 'fx.greeting')->value('addon_key'))->toBe('fx')
        ->and(Setting::where('key', 'site.name')->value('addon_key'))->toBe('system');
});

it('PUT：未声明的键按业务错误拒绝且不落库', function () {
    $this->withToken(admin_token())->putJson('/api/admin/settings', [
        'values' => ['rogue.key' => 'x'],
    ])->assertOk()->assertJsonPath('code', 1)
        ->assertJsonPath('msg', fn ($m) => str_contains((string) $m, '未在任何插件 schema 中声明'));

    expect(Setting::count())->toBe(0);
});

it('服务层归属隔离：插件只能写自己的键，系统键仅 system 可写', function () {
    $store = app(SettingStore::class);

    // 插件写自己的键 ✓
    $store->setFor('fx', ['fx.greeting' => 'from-fx']);
    expect($store->get('fx.greeting'))->toBe('from-fx');

    // 插件写系统键 ✗；写未声明键 ✗
    expect(fn () => $store->setFor('fx', ['site.name' => 'hijack']))
        ->toThrow(SettingException::class, '无权写入');
    expect(fn () => $store->setFor('fx', ['unknown.key' => 1]))
        ->toThrow(SettingException::class, '未在任何插件 schema 中声明');

    // system 归属可写（核心/系统插件上下文）
    $store->setFor('system', ['site.name' => 'ok']);
    expect($store->get('site.name'))->toBe('ok');
});

it('get()：无行时回退 schema 默认，再回退调用方默认', function () {
    $store = app(SettingStore::class);
    expect($store->get('fx.limit'))->toBe(10)          // schema default
        ->and($store->get('fx.missing', 'fallback'))->toBe('fallback');
});

it('类型：jsonb 往返保持标量与布尔', function () {
    $store = app(SettingStore::class);
    $store->set('fx.greeting', true, 'fx');
    expect($store->get('fx.greeting'))->toBeTrue();
    $store->set('fx.greeting', ['a' => 1], 'fx');
    expect($store->get('fx.greeting'))->toBe(['a' => 1]);
});

it('权限：无 system.setting.* 返回 403 信封', function () {
    Admin::create(['username' => 'plain', 'password' => 'x123456', 'status' => 1]);
    $token = $this->postJson('/api/admin/auth/login', ['username' => 'plain', 'password' => 'x123456'])->json('data.token');

    $this->getJson('/api/admin/settings', ['Authorization' => "Bearer {$token}"])
        ->assertOk()->assertJsonPath('code', 403);
    $this->putJson('/api/admin/settings', ['values' => []], ['Authorization' => "Bearer {$token}"])
        ->assertOk()->assertJsonPath('code', 403);
});

it('settings 表存在且重复 PUT 幂等（updateOrCreate 不堆行）', function () {
    $token = admin_token();
    foreach (['一次', '两次'] as $v) {
        $this->withToken($token)->putJson('/api/admin/settings', ['values' => ['fx.greeting' => $v]])->assertOk();
    }
    expect(Schema::hasTable('settings'))->toBeTrue()
        ->and(Setting::where('key', 'fx.greeting')->count())->toBe(1);
});
