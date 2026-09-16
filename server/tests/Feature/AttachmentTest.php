<?php

use App\Admin\Events\AttachmentDeleted;
use App\Admin\Events\AttachmentSaved;
use App\Admin\Models\Admin;
use App\Admin\Models\Attachment;
use App\Admin\Models\Menu;
use App\Admin\Seeds\MenuSeeder;
use App\Admin\Seeds\RbacSeeder;
use App\Admin\Services\AttachmentService;
use App\Admin\Services\MenuService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    (new RbacSeeder)->run();
    (new MenuSeeder)->run();
    Storage::fake('public');
});

it('超管上传图片：行/文件/尺寸/url 齐备且派发 attachment.saved', function () {
    Event::fake([AttachmentSaved::class]);

    $r = $this->withToken(admin_token())->post('/api/admin/attachments', [
        'file' => UploadedFile::fake()->image('封面.png', 800, 600),
    ]);
    $r->assertOk()->assertJsonPath('code', 0);

    $row = $r->json('data');
    expect($row['name'])->toBe('封面.png')
        ->and($row['disk'])->toBe('public')
        ->and($row['width'])->toBe(800)
        ->and($row['height'])->toBe(600)
        ->and($row['mime'])->toStartWith('image/')
        ->and($row['size'])->toBeInt()->toBeGreaterThan(0)
        ->and($row['uploader_type'])->toBe('admin')
        ->and($row['uploader_id'])->toBe(super_admin()->id)
        // url 由 disk 配置推导，指向 /storage/attachments/...
        ->and($row['url'])->toContain('/storage/attachments/')
        ->and(str_contains($row['path'], 'attachments/'))->toBeTrue();

    Storage::disk('public')->assertExists($row['path']);
    Event::assertDispatched(AttachmentSaved::class,
        fn (AttachmentSaved $e) => $e->attachment->id === $row['id']);
});

it('非白名单扩展名 422；超限大小 422', function () {
    $token = admin_token();

    $this->withToken($token)->post('/api/admin/attachments', [
        'file' => UploadedFile::fake()->create('evil.svg', 10, 'image/svg+xml'),
    ])->assertStatus(422);

    config(['arkadmin.attachment.max_size' => 1]); // KB
    $this->withToken($token)->post('/api/admin/attachments', [
        'file' => UploadedFile::fake()->create('big.jpg', 10, 'image/jpeg'),
    ])->assertStatus(422);

    expect(Attachment::count())->toBe(0);
});

it('列表分页/keyword/类型过滤；keyword 通配符按字面量', function () {
    $token = admin_token();
    foreach (['a.jpg', 'b%.jpg', 'c.png'] as $name) {
        $this->withToken($token)->post('/api/admin/attachments',
            ['file' => UploadedFile::fake()->image($name)])->assertOk();
    }

    $all = $this->withToken($token)->getJson('/api/admin/attachments?per_page=2');
    $all->assertOk()->assertJsonPath('code', 0)
        ->assertJsonPath('data.total', 3)
        ->assertJsonPath('data.per_page', 2)
        ->assertJsonCount(2, 'data.list');

    // % 输入按字面量匹配：只命中文件名本身含 % 的那一条
    $this->withToken($token)->getJson('/api/admin/attachments?keyword='.rawurlencode('%.jpg'))
        ->assertOk()->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.list.0.name', 'b%.jpg');

    $this->withToken($token)->getJson('/api/admin/attachments?type=image')
        ->assertOk()->assertJsonPath('data.total', 3);

    // png 也属于 image/*；用非图 mime 的假附件验证 type 过滤不会放行
    Storage::disk('public')->put('attachments/notimg.bin', 'x');
    Attachment::create(['name' => 'doc.bin', 'path' => 'attachments/notimg.bin', 'disk' => 'public',
        'mime' => 'application/octet-stream', 'size' => 1]);
    $this->withToken($token)->getJson('/api/admin/attachments?type=image')
        ->assertOk()->assertJsonPath('data.total', 3);
});

it('删除：行与文件一起删且派发 attachment.deleted', function () {
    Event::fake([AttachmentDeleted::class]);
    $token = admin_token();
    $row = $this->withToken($token)->post('/api/admin/attachments',
        ['file' => UploadedFile::fake()->image('del.png')])->json('data');

    $this->withToken($token)->deleteJson("/api/admin/attachments/{$row['id']}")
        ->assertOk()->assertJsonPath('code', 0);

    expect(Attachment::find($row['id']))->toBeNull();
    Storage::disk('public')->assertMissing($row['path']);
    Event::assertDispatched(AttachmentDeleted::class,
        fn (AttachmentDeleted $e) => $e->attachment->path === $row['path']);
});

it('不信任客户端文件名：图片内容配 .php 名按内容嗅探存储', function () {
    $token = admin_token();

    $r = $this->withToken($token)->post('/api/admin/attachments', [
        'file' => UploadedFile::fake()->image('shell.php'),
    ]);
    $r->assertOk()->assertJsonPath('code', 0);

    $path = $r->json('data.path');
    // fake()->image 实际生成 JPEG 内容：扩展名必须来自内容嗅探（.jpg），与文件名无关
    expect(str_ends_with($path, '.php'))->toBeFalse()
        ->and(str_ends_with($path, '.jpg'))->toBeTrue();
});

it('反向：PHP 内容伪装 .png 名 → 内容嗅探拒绝', function () {
    $token = admin_token();

    $this->withToken($token)->post('/api/admin/attachments', [
        'file' => UploadedFile::fake()->createWithContent('image.png', '<?php echo 1;'),
    ])->assertStatus(422);

    expect(Attachment::count())->toBe(0);
});

it('无 system.attachment.* 权限返回 403 信封', function () {
    Admin::create(['username' => 'plain', 'password' => 'x123456', 'status' => 1]);
    $token = $this->postJson('/api/admin/auth/login', ['username' => 'plain', 'password' => 'x123456'])
        ->json('data.token');

    $this->getJson('/api/admin/attachments', ['Authorization' => "Bearer {$token}"])
        ->assertOk()->assertJsonPath('code', 403);
    $this->postJson('/api/admin/attachments', [], ['Authorization' => "Bearer {$token}"])
        ->assertOk()->assertJsonPath('code', 403);
    $this->deleteJson('/api/admin/attachments/1', [], ['Authorization' => "Bearer {$token}"])
        ->assertOk()->assertJsonPath('code', 403);
});

it('RbacSeeder 种出 attachment 权限且超管拥有；MenuSeeder 种出素材库菜单', function () {
    foreach (['index', 'store', 'destroy'] as $act) {
        $p = Permission::where('name', "system.attachment.{$act}")
            ->where('guard_name', 'admin')->first();
        expect($p)->not->toBeNull()->and($p->module)->toBe('system');
    }
    expect(super_admin()->hasPermissionTo('system.attachment.index'))->toBeTrue()
        ->and(menu_tree_names((new MenuService)->treeFor(super_admin())))->toContain('attachment')
        ->and(Menu::where('name', 'attachment')->value('permission'))
        ->toBe('system.attachment.index');

    // 无权限账号看不到素材库菜单
    $u = Admin::create(['username' => 'noperm', 'password' => 'x123456', 'status' => 1]);
    expect(menu_tree_names((new MenuService)->treeFor($u)))->not->toContain('attachment');
});

// —— 评审轮 R2-9 回归：服务层自保（插件直调）与失败回收 ——

it('服务层自保：白名单外文件直调 store 抛异常，不落库不留文件', function () {
    // 服务是插件可直调的公开接口，不能只靠控制器校验；此前会静默写成 .bin
    expect(fn () => app(AttachmentService::class)
        ->store(UploadedFile::fake()->createWithContent('payload.bin', '<?php echo 1;')))
        ->toThrow(InvalidArgumentException::class);

    expect(Attachment::count())->toBe(0)
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});

it('建行失败时回收已写盘文件（写盘与建行不同事务，不留孤儿文件）', function () {
    Attachment::creating(function () {
        throw new RuntimeException('模拟建行失败');
    });

    try {
        expect(fn () => app(AttachmentService::class)->store(UploadedFile::fake()->image('orphan.png')))
            ->toThrow(RuntimeException::class);
    } finally {
        // 静态监听在模型上注册，用完即清，避免影响同进程后续用例
        Attachment::flushEventListeners();
    }

    expect(Attachment::count())->toBe(0)
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});
