<?php

use App\Admin\Models\Menu;
use App\Admin\Seeds\MenuSeeder;
use App\Admin\Seeds\RbacSeeder;
use App\Admin\Services\MenuService;
use App\Support\Addon\AddonInstaller;
use App\Support\Addon\AddonManager;
use App\Support\Addon\Models\Addon;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    (new RbacSeeder)->run();
    (new MenuSeeder)->run();
    config(['arkadmin.addon_path' => storage_path('framework/addon-fixture')]);
});

afterEach(function () {
    app(AddonManager::class)->flushCompiled();
});

// §10 M5 完成标志的自动化形态：对新表一条命令生成可用完整模块
it('生成 → 安装 → 菜单/权限就位 → HTTP CRUD 全通 → 卸载干净', function () {
    $dir = make_crud_addon('fx');
    make_fixture_installable($dir, 'fx');
    create_items_table();

    $this->artisan('ark:crud', ['--table' => 'fx_items'])->assertExitCode(0);

    // 未安装态：代码已生成，命令不崩（菜单/权限由 install 流程负责）
    app(AddonInstaller::class)->install('fx');

    // 生成器追加的菜单条目随安装写入 DB 并渲染进菜单树
    expect(menu_tree_names((new MenuService)->treeFor(super_admin())))
        ->toContain('fx', 'fx.item')
        ->and(Permission::where('module', 'fx')->pluck('name'))
        ->toContain('addon.fx.item.index', 'addon.fx.item.destroy');

    $token = admin_token();
    $h = ['Authorization' => "Bearer {$token}"];

    // store（含布尔/整型/长文本）
    $id = $this->postJson('/api/admin/addon/fx/items', [
        'title' => '第一件', 'content' => '正文内容', 'views' => 5, 'is_top' => true,
    ], $h)->assertOk()->assertJsonPath('code', 0)->json('data.id');
    expect($id)->toBeInt();

    // list + keyword（首个 varchar 列）
    $this->getJson('/api/admin/addon/fx/items?keyword='.rawurlencode('第一'), $h)
        ->assertOk()->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.list.0.title', '第一件');

    // show / update（PUT 部分语义：不传 content 不清空）
    $this->putJson("/api/admin/addon/fx/items/{$id}", ['title' => '改名'], $h)->assertOk()->assertJsonPath('code', 0);
    $this->getJson("/api/admin/addon/fx/items/{$id}", $h)
        ->assertOk()->assertJsonPath('data.title', '改名')
        ->assertJsonPath('data.content', '正文内容');

    // 校验：超长 title 422（varchar(100) → max:100）
    $this->postJson('/api/admin/addon/fx/items', ['title' => str_repeat('长', 101)], $h)->assertStatus(422);

    // destroy
    $this->deleteJson("/api/admin/addon/fx/items/{$id}", [], $h)->assertOk()->assertJsonPath('code', 0);

    // 卸载回路：注册行/菜单/权限清除（fx_items 表由测试自建，不属插件迁移，不在断言范围）
    app(AddonInstaller::class)->disable('fx');
    app(AddonInstaller::class)->uninstall('fx');
    expect(Addon::find('fx'))->toBeNull()
        ->and(Menu::where('addon_key', 'fx')->count())->toBe(0)
        ->and(Permission::where('module', 'fx')->count())->toBe(0);
});
