<?php

use Addons\cms\Models\Article;
use Addons\cms\Models\Category;
use App\Admin\Models\Admin;
use App\Admin\Models\Menu;
use App\Admin\Seeds\MenuSeeder;
use App\Admin\Seeds\RbacSeeder;
use App\Admin\Services\MenuService;
use App\Support\Addon\AddonInstaller;
use App\Support\Addon\AddonManager;
use App\Support\Addon\Models\Addon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    (new RbacSeeder)->run();
    (new MenuSeeder)->run();
});

afterEach(function () {
    app(AddonManager::class)->flushCompiled();
});

it('安装 cms：表/菜单/权限/种子数据齐备', function () {
    app(AddonInstaller::class)->install('cms');

    expect(Schema::hasTable('cms_categories'))->toBeTrue()
        ->and(Schema::hasTable('cms_articles'))->toBeTrue()
        // §6.3 install 钩子种子：默认栏目 + 示例文章
        ->and(Category::count())->toBe(1)
        ->and(Article::count())->toBe(1)
        ->and(Menu::where('addon_key', 'cms')->count())->toBe(3)
        ->and(Permission::where('module', 'cms')->count())->toBe(8)
        // RbacSeeder 语义延续：插件新权限自动授予超管
        ->and(Role::where('name', 'super_admin')
            ->where('guard_name', 'admin')->first()->hasPermissionTo('addon.cms.article.store'))->toBeTrue()
        // 菜单树出现 CMS 三个节点
        ->and(menu_tree_names((new MenuService)->treeFor(super_admin())))
        ->toContain('cms', 'cms.category', 'cms.article');
});

// —— T4 栏目 API ——

function install_cms(): string
{
    app(AddonInstaller::class)->install('cms');

    return admin_token();
}

it('栏目：建根/建子，树形返回含嵌套与文章数', function () {
    $token = install_cms();

    $this->postJson('/api/admin/addon/cms/categories', ['name' => '新闻'], [
        'Authorization' => "Bearer {$token}",
    ])->assertOk()->assertJsonPath('code', 0);

    $tree = $this->getJson('/api/admin/addon/cms/categories', ['Authorization' => "Bearer {$token}"])
        ->assertOk()->assertJsonPath('code', 0)->json('data');

    // install 种子（默认栏目）+ 新建的「新闻」
    expect(count($tree))->toBe(2)->and($tree[0]['article_count'])->toBe(1);

    $parentId = collect($tree)->firstWhere('name', '新闻')['id'];
    $this->postJson('/api/admin/addon/cms/categories', ['parent_id' => $parentId, 'name' => '公司新闻'], [
        'Authorization' => "Bearer {$token}",
    ])->assertOk();

    $tree = $this->getJson('/api/admin/addon/cms/categories', ['Authorization' => "Bearer {$token}"])->json('data');
    $news = collect($tree)->firstWhere('name', '新闻');
    expect($news['children'])->toHaveCount(1)->and($news['children'][0]['name'])->toBe('公司新闻');
});

it('栏目：校验缺失名称 422，父级设为自己/子孙返回 code 1', function () {
    $token = install_cms();
    $headers = ['Authorization' => "Bearer {$token}"];

    $this->postJson('/api/admin/addon/cms/categories', ['name' => ''], $headers)->assertStatus(422);

    $id = $this->postJson('/api/admin/addon/cms/categories', ['name' => '根'], $headers)->json('data.id');
    $child = $this->postJson('/api/admin/addon/cms/categories', ['parent_id' => $id, 'name' => '子'], $headers)->json('data.id');

    // 自己做自己的父级
    $this->putJson("/api/admin/addon/cms/categories/{$id}",
        ['parent_id' => $id, 'name' => '根'], $headers)->assertOk()->assertJsonPath('code', 1);
    // 子做父（成环）
    $this->putJson("/api/admin/addon/cms/categories/{$id}",
        ['parent_id' => $child, 'name' => '根'], $headers)->assertOk()->assertJsonPath('code', 1);

    // 正常编辑通过
    $this->putJson("/api/admin/addon/cms/categories/{$id}",
        ['parent_id' => 0, 'name' => '根改名', 'description' => 'd', 'sort' => 5, 'is_show' => false], $headers)
        ->assertOk()->assertJsonPath('code', 0);
    expect(Category::find($id)->name)->toBe('根改名')
        ->and(Category::find($id)->is_show)->toBeFalse();
});

it('栏目：有文章/有子栏目时拒绝删除', function () {
    $token = install_cms();
    $headers = ['Authorization' => "Bearer {$token}"];

    $cid = $this->postJson('/api/admin/addon/cms/categories', ['name' => '待删栏目'], $headers)->json('data.id');
    $this->postJson('/api/admin/addon/cms/articles', [
        'category_id' => $cid, 'title' => '占用文章', 'status' => 0,
    ], $headers)->assertOk();

    $this->deleteJson("/api/admin/addon/cms/categories/{$cid}", [], $headers)
        ->assertOk()->assertJsonPath('code', 1)
        ->assertJsonPath('msg', '该栏目下还有文章，无法删除');

    // 删掉文章后可删除栏目
    $aid = Article::where('title', '占用文章')->value('id');
    $this->deleteJson("/api/admin/addon/cms/articles/{$aid}", [], $headers)->assertOk();
    $this->deleteJson("/api/admin/addon/cms/categories/{$cid}", [], $headers)->assertOk()->assertJsonPath('code', 0);
    expect(Category::find($cid))->toBeNull();
});

it('栏目：无 addon.cms.category.* 权限返回 403 信封', function () {
    $token = install_cms();
    Admin::create(['username' => 'plain', 'password' => 'x123456', 'status' => 1]);
    $t2 = $this->postJson('/api/admin/auth/login', ['username' => 'plain', 'password' => 'x123456'])->json('data.token');

    $this->getJson('/api/admin/addon/cms/categories', ['Authorization' => "Bearer {$t2}"])
        ->assertOk()->assertJsonPath('code', 403);
    $this->postJson('/api/admin/addon/cms/categories', ['name' => 'x'], ['Authorization' => "Bearer {$t2}"])
        ->assertOk()->assertJsonPath('code', 403);
});

// —— T5 文章 API ——

it('文章：发布携带 tags/cover，published_at 自动落，jsonb 往返为数组', function () {
    $token = install_cms();
    $headers = ['Authorization' => "Bearer {$token}"];
    $cid = Category::first()->id;

    $r = $this->postJson('/api/admin/addon/cms/articles', [
        'category_id' => $cid,
        'title' => '第一篇',
        'summary' => '摘要',
        'content' => '<p>正文 <strong>富文本</strong></p>',
        'cover' => 'attachments/202609/abc.jpg',
        'tags' => ['公告', 'hot news'],
        'status' => 1,
    ], $headers)->assertOk()->assertJsonPath('code', 0);

    $a = Article::findOrFail($r->json('data.id'));
    expect($a->tags)->toBe(['公告', 'hot news'])           // jsonb → array 往返
        ->and($a->cover)->toBe('attachments/202609/abc.jpg')
        ->and($a->published_at)->not->toBeNull()
        ->and($a->status)->toBe(1);

    // 草稿：无发布时间
    $this->postJson('/api/admin/addon/cms/articles', [
        'category_id' => $cid, 'title' => '草稿稿', 'status' => 0,
    ], $headers)->assertOk();
    expect(Article::where('title', '草稿稿')->first()->published_at)->toBeNull();
});

it('文章：校验（栏目必须存在/tags 结构/status 枚举）422', function () {
    $token = install_cms();
    $headers = ['Authorization' => "Bearer {$token}"];

    $this->postJson('/api/admin/addon/cms/articles', ['category_id' => 99999, 'title' => 'x', 'status' => 0], $headers)
        ->assertStatus(422);
    $this->postJson('/api/admin/addon/cms/articles', [
        'category_id' => Category::first()->id, 'title' => 'x',
        'tags' => 'a,b,c', 'status' => 0,   // 禁止逗号分隔字符串习惯（§5.4）
    ], $headers)->assertStatus(422);
    $this->postJson('/api/admin/addon/cms/articles', [
        'category_id' => Category::first()->id, 'title' => 'x', 'status' => 9,
    ], $headers)->assertStatus(422);
});

it('文章：列表过滤/分页/剔除 content/带栏目名；show 全量', function () {
    $token = install_cms();
    $headers = ['Authorization' => "Bearer {$token}"];
    $c1 = Category::first()->id;
    $c2 = $this->postJson('/api/admin/addon/cms/categories', ['name' => '另一栏目'], $headers)->json('data.id');

    foreach ([
        ['category_id' => $c1, 'title' => 'PG 指南', 'status' => 1, 'tags' => ['db']],
        ['category_id' => $c1, 'title' => 'PG 进阶', 'status' => 0, 'tags' => []],
        ['category_id' => $c2, 'title' => '公告一', 'status' => 1, 'tags' => ['news']],
    ] as $row) {
        $this->postJson('/api/admin/addon/cms/articles', $row + ['content' => '<p>heavy</p>'], $headers)->assertOk();
    }

    // 关键字 + 通配符字面量
    $this->getJson('/api/admin/addon/cms/articles?keyword='.rawurlencode('PG'), $headers)
        ->assertOk()->assertJsonPath('data.total', 2);
    $list = $this->getJson('/api/admin/addon/cms/articles?category_id='.$c2, $headers)->json('data');
    expect($list['total'])->toBe(1)
        ->and($list['list'][0]['title'])->toBe('公告一')
        ->and($list['list'][0]['category']['name'])->toBe('另一栏目')
        ->and(array_key_exists('content', $list['list'][0]))->toBeFalse()   // 列表不拖富文本
        ->and($list['list'][0]['tags'])->toBe(['news']);

    // 已发布 = 公告一 + PG 指南 + install 种子的示例文章
    $this->getJson('/api/admin/addon/cms/articles?status=1', $headers)->assertOk()->assertJsonPath('data.total', 3);

    $id = $list['list'][0]['id'];
    $show = $this->getJson("/api/admin/addon/cms/articles/{$id}", $headers)->json('data');
    expect($show['content'])->toBe('<p>heavy</p>');
});

it('文章：更新与删除', function () {
    $token = install_cms();
    $headers = ['Authorization' => "Bearer {$token}"];
    $cid = Category::first()->id;
    $aid = $this->postJson('/api/admin/addon/cms/articles',
        ['category_id' => $cid, 'title' => '初稿', 'status' => 0], $headers)->json('data.id');

    $this->putJson("/api/admin/addon/cms/articles/{$aid}", [
        'category_id' => $cid, 'title' => '终稿', 'tags' => ['done'], 'status' => 1,
    ], $headers)->assertOk()->assertJsonPath('code', 0);
    $a = Article::findOrFail($aid);
    expect($a->title)->toBe('终稿')->and($a->tags)->toBe(['done'])->and($a->published_at)->not->toBeNull();

    $this->deleteJson("/api/admin/addon/cms/articles/{$aid}", [], $headers)->assertOk()->assertJsonPath('code', 0);
    expect(Article::find($aid))->toBeNull();
});

// 403 用例必须让无权限账号发起本测试内首个带认证的请求：
// Sanctum guard 在同一测试进程内缓存首个解析用户（超管先认证则 plain 会被缓存顶掉，
// 生产环境每请求独立进程无此问题；AddonLifecycleTest 同形态）
it('文章：无 addon.cms.article.* 权限 403', function () {
    app(AddonInstaller::class)->install('cms');
    Admin::create(['username' => 'plain', 'password' => 'x123456', 'status' => 1]);
    $t2 = $this->postJson('/api/admin/auth/login', ['username' => 'plain', 'password' => 'x123456'])->json('data.token');

    $cid = Category::first()->id;
    $this->getJson('/api/admin/addon/cms/articles', ['Authorization' => "Bearer {$t2}"])
        ->assertOk()->assertJsonPath('code', 403);
    $this->postJson('/api/admin/addon/cms/articles', ['category_id' => $cid, 'title' => 'x', 'status' => 0],
        ['Authorization' => "Bearer {$t2}"])->assertOk()->assertJsonPath('code', 403);
});

// —— 评审轮回归：PUT 部分语义 / 树深度 / 富文本净化 / 素材删除联动 ——

it('文章 PUT 部分语义：不传 tags 不清空；编辑已发布文章不刷新发布时间；转草稿清空', function () {
    $token = install_cms();
    $headers = ['Authorization' => "Bearer {$token}"];
    $cid = Category::first()->id;
    $aid = $this->postJson('/api/admin/addon/cms/articles', [
        'category_id' => $cid, 'title' => '语义稿', 'tags' => ['保留'], 'status' => 1,
    ], $headers)->json('data.id');

    $before = Article::findOrFail($aid)->published_at;

    // 不带 tags 的全量 PUT（status=1）：标签保留、发布时间不刷新
    $this->putJson("/api/admin/addon/cms/articles/{$aid}", [
        'category_id' => $cid, 'title' => '语义稿2', 'status' => 1,
    ], $headers)->assertOk()->assertJsonPath('code', 0);
    $a = Article::findOrFail($aid);
    expect($a->tags)->toBe(['保留'])
        ->and($a->published_at->equalTo($before))->toBeTrue();

    // 显式提交 tags = 覆盖；转草稿 → 发布时间清空
    $this->putJson("/api/admin/addon/cms/articles/{$aid}", [
        'category_id' => $cid, 'title' => '语义稿3', 'tags' => ['新标签'], 'status' => 0,
    ], $headers)->assertOk();
    $a = Article::findOrFail($aid);
    expect($a->tags)->toBe(['新标签'])->and($a->published_at)->toBeNull();

    // 草稿再发布 → 首次落发布时间
    $this->putJson("/api/admin/addon/cms/articles/{$aid}", [
        'category_id' => $cid, 'title' => '语义稿3', 'status' => 1,
    ], $headers)->assertOk();
    expect(Article::findOrFail($aid)->published_at)->not->toBeNull();
});

it('栏目树：深层链路线性返回，article_count 跨级累加且列表过滤含子栏目', function () {
    $token = install_cms();
    $headers = ['Authorization' => "Bearer {$token}"];

    // 造 3 层树：根 → 子 → 孙，文章挂在孙栏目
    $rootId = $this->postJson('/api/admin/addon/cms/categories', ['name' => '根'], $headers)->json('data.id');
    $childId = $this->postJson('/api/admin/addon/cms/categories', ['parent_id' => $rootId, 'name' => '子'], $headers)->json('data.id');
    $grandId = $this->postJson('/api/admin/addon/cms/categories', ['parent_id' => $childId, 'name' => '孙'], $headers)->json('data.id');
    $this->postJson('/api/admin/addon/cms/articles', [
        'category_id' => $grandId, 'title' => '孙栏目文章', 'status' => 1,
    ], $headers)->assertOk();

    $tree = $this->getJson('/api/admin/addon/cms/categories', $headers)->json('data');
    $root = collect($tree)->firstWhere('name', '根');
    expect($root['article_count'])->toBe(1)                     // 孙栏目文章跨级计入
        ->and($root['children'][0]['children'][0]['name'])->toBe('孙');

    // 列表按根栏目过滤含子栏目（与树计数语义一致）
    $this->getJson('/api/admin/addon/cms/articles?category_id='.$rootId, $headers)
        ->assertOk()->assertJsonPath('data.total', 1);

    // 30 层链（评审 C1 回归：nest 必须线性，接口须正常返回）
    $parentId = $grandId;
    for ($i = 0; $i < 30; $i++) {
        $parentId = $this->postJson('/api/admin/addon/cms/categories',
            ['parent_id' => $parentId, 'name' => "L{$i}"], $headers)->json('data.id');
    }
    $deep = $this->getJson('/api/admin/addon/cms/categories', $headers);
    $deep->assertOk()->assertJsonPath('code', 0);
    expect(count($deep->json('data')))->toBe(2);   // 默认栏目 + 根
});

it('文章正文存储前净化：剥离事件属性/script/iframe/危险协议', function () {
    $token = install_cms();
    $headers = ['Authorization' => "Bearer {$token}"];
    $cid = Category::first()->id;

    $this->postJson('/api/admin/addon/cms/articles', [
        'category_id' => $cid,
        'title' => 'XSS 载荷',
        'content' => '<p onclick="alert(1)">段落</p><script>alert(2)</script>'
            .'<img src="/ok.png" onerror="alert(3)"><a href="javascript:alert(4)">链接</a>'
            .'<iframe src="//evil.example"></iframe><style>body{}</style><em>保留</em>',
        'status' => 0,
    ], $headers)->assertOk();

    $content = Article::where('title', 'XSS 载荷')->value('content');
    expect($content)->not->toContain('onclick')
        ->not->toContain('onerror')
        ->not->toContain('<script')
        ->not->toContain('iframe')
        ->not->toContain('<style')
        ->not->toContain('javascript:')
        ->toContain('<em>保留</em>')
        ->toContain('段落')
        ->toContain('src="/ok.png"');
});

it('素材删除联动清空文章封面（attachment.deleted → CMS 监听）', function () {
    Storage::fake('public');
    $token = install_cms();
    $headers = ['Authorization' => "Bearer {$token}"];

    $up = $this->withToken($token)->post('/api/admin/attachments',
        ['file' => UploadedFile::fake()->image('cover.png')])->json('data');
    $aid = $this->postJson('/api/admin/addon/cms/articles', [
        'category_id' => Category::first()->id,
        'title' => '带封面文章', 'cover' => $up['path'], 'status' => 1,
    ], $headers)->json('data.id');

    $this->withToken($token)->deleteJson("/api/admin/attachments/{$up['id']}")->assertOk();

    $a = Article::findOrFail($aid);
    expect($a->cover)->toBe('')->and($a->cover_url)->toBe('');
});

// —— §9 验收流程 1–6 自动化 ——

it('§9-3 绑定权限到角色后按权限裁剪菜单：只授权文章可见栏目不可见', function () {
    app(AddonInstaller::class)->install('cms');

    $u = Admin::create(['username' => 'editor', 'password' => 'x123456', 'status' => 1]);
    $role = Role::create(['name' => 'cms_editor', 'guard_name' => 'admin']);
    $role->syncPermissions(['addon.cms.article.index']);
    $u->assignRole($role);

    // 受限账号本测试内首个带认证请求：/auth/me 返回按权限裁剪后的菜单树
    $token = $this->postJson('/api/admin/auth/login', ['username' => 'editor', 'password' => 'x123456'])
        ->json('data.token');
    $names = menu_tree_names($this->getJson('/api/admin/auth/me', ['Authorization' => "Bearer {$token}"])
        ->json('data.menus'));
    expect($names)->toContain('cms', 'cms.article')->not->toContain('cms.category');

    $this->getJson('/api/admin/addon/cms/categories', ['Authorization' => "Bearer {$token}"])
        ->assertOk()->assertJsonPath('code', 403);
    $this->getJson('/api/admin/addon/cms/articles', ['Authorization' => "Bearer {$token}"])
        ->assertOk()->assertJsonPath('code', 0);

    // 超管菜单树不经 HTTP（避免 guard 缓存串号）：完整含栏目
    expect(menu_tree_names((new MenuService)->treeFor(super_admin())))->toContain('cms.category');
});

it('§9-4 disable：菜单消失、路由 404、数据保留', function () {
    $token = install_cms();
    $this->postJson('/api/admin/addon/cms/articles', [
        'category_id' => Category::first()->id, 'title' => '保留我', 'status' => 0,
    ], ['Authorization' => "Bearer {$token}"])->assertOk();

    app(AddonInstaller::class)->disable('cms');

    expect(Addon::find('cms')->enabled)->toBeFalse()
        // 菜单行保留但不渲染
        ->and(Menu::where('addon_key', 'cms')->count())->toBe(3)
        ->and(menu_tree_names((new MenuService)->treeFor(super_admin())))->not->toContain('cms')
        // 业务表与数据保留
        ->and(Article::where('title', '保留我')->exists())->toBeTrue();

    remount_routes();
    // 注意：disable 后 super token 仍有效，plain 首请求规则不适用（本测试无第二用户）
    $this->getJson('/api/admin/addon/cms/categories', ['Authorization' => "Bearer {$token}"])
        ->assertOk()->assertJsonPath('code', 404);
});

it('§9-5 enable：完整恢复', function () {
    $installer = app(AddonInstaller::class);
    $installer->install('cms');
    $installer->disable('cms');
    $installer->enable('cms');

    expect(menu_tree_names((new MenuService)->treeFor(super_admin())))->toContain('cms', 'cms.category');

    remount_routes();
    $token = admin_token(); // enable 后首个带认证请求是超管，串号规则不适用
    $this->getJson('/api/admin/addon/cms/categories', ['Authorization' => "Bearer {$token}"])
        ->assertOk()->assertJsonPath('code', 0);
    expect(Category::count())->toBe(1);
});

it('§9-6 uninstall：菜单/权限/表/前端产物清除', function () {
    $installer = app(AddonInstaller::class);
    $installer->install('cms');
    $installer->disable('cms');
    $installer->uninstall('cms');

    expect(Addon::find('cms'))->toBeNull()
        ->and(Menu::where('addon_key', 'cms')->count())->toBe(0)
        ->and(Permission::where('module', 'cms')->count())->toBe(0)
        ->and(Schema::hasTable('cms_categories'))->toBeFalse()
        ->and(Schema::hasTable('cms_articles'))->toBeFalse()
        ->and(is_dir(config('arkadmin.admin_path').'/src/addons/cms'))->toBeFalse();
});

it('§9-6 --keep-data 卸载保留业务表', function () {
    $token = install_cms();
    $this->postJson('/api/admin/addon/cms/articles', [
        'category_id' => Category::first()->id, 'title' => 'keep', 'status' => 0,
    ], ['Authorization' => "Bearer {$token}"])->assertOk();

    app(AddonInstaller::class)->disable('cms');
    app(AddonInstaller::class)->uninstall('cms', keepData: true);

    expect(Schema::hasTable('cms_articles'))->toBeTrue()
        ->and(Article::where('title', 'keep')->exists())->toBeTrue()
        ->and(Addon::find('cms'))->toBeNull()
        ->and(Menu::where('addon_key', 'cms')->count())->toBe(0);
});

it('§9-6b 卸载后可重新安装（种子重灌）', function () {
    $installer = app(AddonInstaller::class);
    $installer->install('cms');
    $installer->disable('cms');
    $installer->uninstall('cms');
    $installer->install('cms');

    expect(Addon::find('cms')->enabled)->toBeTrue()
        ->and(Category::count())->toBe(1)
        ->and(Menu::where('addon_key', 'cms')->count())->toBe(3);
});
