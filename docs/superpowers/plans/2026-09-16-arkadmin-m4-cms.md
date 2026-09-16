# ArkAdmin M4 CMS 插件 — 实施计划

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 实现设计文档 §9——框架级素材库（attachments 上传/列表/删除 + `attachment.saved` 埋点）与首个业务插件 CMS（栏目树 + 文章富文本/封面/标签/状态），并完成 §9 验收流程 1–7 的自动化与手工验证。

**Architecture:** 素材库是框架能力（§5.4），落在 `app/Admin/`（迁移/模型/事件/服务/控制器）+ `system.attachment.*` 权限 + 框架「素材库」页面与共享选择器组件，磁盘用 Laravel `public`（storage/app/public + `php artisan storage:link`，nginx 已挂整个仓库可直读软链）。CMS 按 §6.1 目录约定建在 `addons/cms/`（`Addons\cms\` 命名空间、`cms_` 表前缀、匿名类迁移），控制器瘦/服务厚，业务守卫用插件级异常 → 控制器转 `fail(1,msg)`（沿用框架 AdminController 业务错误约定）；前端页面照 M1/M3 既有模板写，正文插图与封面消费框架素材库 API。

**Tech Stack:** Laravel 13 / Pest 4（docker 内跑）；Vue3 + TS + Element Plus + Vite 8 + Vitest；富文本新增依赖 `quill@^2`（npm 腾讯镜像已验证可达 2.0.3）。

**Spec:** `docs/superpowers/specs/2026-09-14-arkadmin-design.md` §5.4（attachments 表/PG 原生类型）、§5.5（attachment.saved 埋点）、§6.1–6.6（插件目录/生命周期/菜单注入/前端同步）、§7.1（attachment 框架页面）、§9（CMS 功能与验收流程 1–7）、§10 M4 行。

## Global Constraints

- 插件命名空间 `Addons\cms\`（全小写=目录名）；表前缀 `cms_`；迁移用匿名类（addons 不在 composer classmap）
- 权限串：插件 `addon.cms.<controller>.<action>`；框架素材库 `system.attachment.<action>`（进 RbacSeeder 矩阵）
- 响应信封 `{"code":0,"data":{},"msg":"ok"}` 恒 HTTP 200（仅 401/422/HTTP 异常例外）；分页 `data.list/total/page/per_page`，前端每页固定 15
- §5.4：多值字段禁用逗号分隔字符串。**tags 用 `jsonb` + Eloquent `array` cast**（§5.4 允许 text[] 或 jsonb；jsonb 免写自定义 PG array cast，风险最低）
- 附件磁盘 `public`（Laravel 惯例），上传目录 `attachments/YYYYMM/<32位随机>.<ext>`；url 由 disk 配置推导（APP_URL + `/storage/...`），dev 需 `APP_URL=http://localhost:8080` + `php artisan storage:link`
- `admin/src/addons/` 是生成产物（gitignore），插件前端唯一来源 `addons/cms/admin/`；安装后需 `npm run build`（dev 热更直接生效）
- 插件前端可引框架公开件：`@/app/api/request`、`@/app/api/attachment`、`@/app/components/AttachmentPicker.vue`（M4 新增，视为框架公开组件）
- 后端测试在 docker 内跑；`Tests\TestCase` 已把 `admin_path` 隔离到 `storage/framework/admin-test`；附件测试用 `Storage::fake('public')`
- NiuShop 只学机制零复制；FastAdmin（Apache-2.0）可借鉴；M1/M2/M3 约定与测试不回退（基线 102 passed / 269 assertions）

## File Structure

```
server/
├── config/arkadmin.php                                  # 改：+ attachment 配置组
├── database/migrations/2026_09_16_000001_create_attachments_table.php   # 新
├── app/Support/Http/PgLike.php                          # 新：LIKE/ILIKE 通配符转义（AdminController 与 CMS 共用）
├── app/Admin/
│   ├── Models/Attachment.php                            # 新
│   ├── Events/AttachmentSaved.php                       # 新（§5.5）
│   ├── Services/AttachmentService.php                   # 新
│   ├── Http/Controllers/AttachmentController.php        # 新
│   ├── Seeds/RbacSeeder.php                             # 改：矩阵 + attachment
│   └── Seeds/MenuSeeder.php                             # 改：+ 素材库顶级菜单
├── routes/api.php                                       # 改：+ attachments 3 路由
└── tests/Feature/AttachmentTest.php                     # 新

addons/cms/                                              # 新：CMS 插件（§6.1 全套）
├── info.json
├── src/
│   ├── Addon.php                                        # Lifecycle：install 种默认栏目+示例文章
│   ├── AddonServiceProvider.php
│   ├── Support/CmsException.php
│   ├── Models/{Category,Article}.php
│   ├── Services/{CategoryService,ArticleService}.php
│   ├── Http/Controllers/{CategoryController,ArticleController}.php
│   └── Http/Requests/{ArticleStoreRequest,ArticleUpdateRequest}.php
├── routes/admin.php
├── database/{migrations/×2, menus.php, permissions.php}
└── admin/
    ├── api/{category.ts,article.ts}
    ├── lang/zh-cn.ts
    ├── components/RichEditor.vue                        # quill 封装，插图走框架素材库
    └── views/{category/index.vue, article/index.vue}

admin/
├── package.json                                         # 改：+ quill
├── src/app/api/attachment.ts                            # 新
├── src/app/utils/attachment.ts                          # 新（formatFileSize/isImage，Vitest 覆盖）
├── src/app/components/AttachmentPicker.vue              # 新（框架共享选择器）
├── src/app/views/attachment/index.vue                   # 新（素材库页）
├── src/app/lang/index.ts                                # 改：common + upload/copy/copied/choose/preview
└── tests/attachment.test.ts                             # 新

.gitignore                                               # 改：+ /server/public/storage
AGENTS.md                                                # 改：storage:link 常用命令、cms 插件注记
docs/superpowers/plans/2026-09-16-arkadmin-m4-wrapup.md  # 新（T7 产出）
```

---

### Task 1: 框架素材库后端（§5.4/§5.5）

**Files:**
- Create: `server/database/migrations/2026_09_16_000001_create_attachments_table.php`
- Create: `server/app/Admin/Models/Attachment.php`、`server/app/Admin/Events/AttachmentSaved.php`、`server/app/Admin/Services/AttachmentService.php`、`server/app/Admin/Http/Controllers/AttachmentController.php`、`server/app/Support/Http/PgLike.php`
- Modify: `server/config/arkadmin.php`、`server/routes/api.php`、`server/app/Admin/Seeds/RbacSeeder.php`、`server/app/Admin/Seeds/MenuSeeder.php`、`server/app/Admin/Http/Controllers/AdminController.php`（改用 PgLike，纯重构）
- Test: `server/tests/Feature/AttachmentTest.php`

**Interfaces:**
- Produces:
  - `App\Admin\Models\Attachment`（fillable 全列、`$appends=['url']`、`url` accessor = `Storage::disk($disk)->url($path)`、`isImage(): bool`）
  - `App\Admin\Events\AttachmentSaved`（`public Attachment $attachment`，§5.5 框架公开埋点）
  - `App\Admin\Services\AttachmentService::store(UploadedFile $file, ?Admin $admin = null): Attachment`、`destroy(Attachment $a): void`
  - `App\Support\Http\PgLike::wrap(string $value): string`（`%`/`_`/`\` 转义并包 `%…%`）
  - API：`GET /api/admin/attachments`（`system.attachment.index`）、`POST /api/admin/attachments`（multipart `file`，`system.attachment.store`）、`DELETE /api/admin/attachments/{id}`（`system.attachment.destroy`）
  - `config('arkadmin.attachment')` = `['disk'=>'public', 'extensions'=>['jpg','jpeg','png','gif','webp','bmp'], 'max_size'=>10240]`（KB）
  - `system.attachment.{index,store,destroy}` 权限由 RbacSeeder 矩阵种出；`attachment` 顶级菜单（`system.attachment.index`）由 MenuSeeder 种出

- [ ] **Step 1: 写失败测试** — `server/tests/Feature/AttachmentTest.php`（用例清单见 Step 4 后测试代码）

- [ ] **Step 2: 跑测试确认失败** — `docker compose -f docker/docker-compose.yml exec -u 1000:1000 php sh -c "cd /var/www/server && php artisan test tests/Feature/AttachmentTest.php"` → 全红（表不存在/404）

- [ ] **Step 3: 实现**（迁移/模型/事件/服务/控制器/路由/种子/config/PgLike）：

```php
// database/migrations/2026_09_16_000001_create_attachments_table.php
Schema::create('attachments', function (Blueprint $table) {
    $table->id();
    $table->string('name', 191);                    // 原始文件名
    $table->string('path', 191)->index();           // 相对 disk 的存储路径（CMS cover 存它）
    $table->string('disk', 32)->default('public');
    $table->string('mime', 128)->default('');
    $table->unsignedBigInteger('size')->default(0);
    $table->unsignedInteger('width')->nullable();
    $table->unsignedInteger('height')->nullable();
    $table->string('uploader_type', 32)->default('admin')->index();
    $table->unsignedBigInteger('uploader_id')->nullable();
    $table->timestamps();
});

// app/Support/Http/PgLike.php —— AdminController 现有内联闭包上提为框架工具（PG 的 LIKE 转义符是反斜杠）
class PgLike
{
    public static function wrap(string $value): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value).'%';
    }
}

// app/Admin/Services/AttachmentService.php（核心逻辑）
public function store(UploadedFile $file, ?Admin $admin = null): Attachment
{
    $disk = (string) config('arkadmin.attachment.disk', 'public');
    $ext = strtolower($file->extension() ?: $file->getClientOriginalExtension());
    // 随机文件名防覆盖/防路径穿越；原始名只入 name 列
    $path = $file->storeAs('attachments/'.date('Ym'), \Illuminate\Support\Str::random(32).'.'.$ext, $disk);
    $info = @getimagesize(Storage::disk($disk)->path($path));   // 非图片/失败 → false
    $attachment = Attachment::create([
        'name' => mb_substr($file->getClientOriginalName(), 0, 191),
        'path' => $path, 'disk' => $disk,
        'mime' => (string) ($file->getMimeType() ?: ''),
        'size' => (int) $file->getSize(),
        'width' => $info === false ? null : (int) $info[0],
        'height' => $info === false ? null : (int) $info[1],
        'uploader_type' => 'admin', 'uploader_id' => $admin?->id,
    ]);
    event(new AttachmentSaved($attachment));   // §5.5
    return $attachment;
}
public function destroy(Attachment $a): void
{
    Storage::disk($a->disk)->delete($a->path);
    $a->delete();
}

// app/Admin/Http/Controllers/AttachmentController.php
public function index(Request $request): JsonResponse   // keyword(ilike name)+type=image(mime like image/%)+分页
public function store(Request $request): JsonResponse
{
    $request->validate([
        'file' => ['required', 'file',
            'max:'.(int) config('arkadmin.attachment.max_size', 10240),
            'mimes:'.implode(',', (array) config('arkadmin.attachment.extensions'))],
    ]);
    return $this->success($this->service->store($request->file('file'), $request->user('admin')), '上传成功');
}
public function destroy(int $attachment): JsonResponse  // findOrFail → service->destroy
```

`routes/api.php` auth 组内追加三行（同框架既有路由风格，permission 中间件）；`RbacSeeder` 矩阵加 `'attachment'`；`MenuSeeder` 在 dashboard 与 system 之间插入：

```php
$upsert(['name' => 'attachment', 'title' => '素材库', 'icon' => 'Picture',
    'route_path' => '/attachment', 'view_path' => 'attachment/index',
    'sort' => 90, 'is_show' => true, 'addon_key' => '', 'permission' => 'system.attachment.index']);
```

`AdminController` 的两处内联 `$like` 闭包改为 `PgLike::wrap(...)`（行为不变的纯重构，既有 ilike 转义测试守护）。

- [ ] **Step 4: 测试转绿** — 测试代码（`Storage::fake('public')`，`beforeEach` 跑 RbacSeeder+MenuSeeder）：

```php
it('超管上传图片：行/文件/尺寸/url 齐备且派发 attachment.saved', function () {
    Storage::fake('public');
    Event::fake([AttachmentSaved::class]);
    $r = $this->withToken(admin_token())->post('/api/admin/attachments', [
        'file' => UploadedFile::fake()->image('封面.png', 800, 600),
    ]);
    $r->assertOk()->assertJsonPath('code', 0);
    $row = $r->json('data');
    expect($row['name'])->toBe('封面.png')->and($row['disk'])->toBe('public')
        ->and($row['width'])->toBe(800)->and($row['height'])->toBe(600)
        ->and($row['mime'])->toStartWith('image/')
        ->and($row['url'])->toContain('/storage/attachments/')
        ->and(str_contains($row['path'], 'attachments/'))->toBeTrue();
    Storage::disk('public')->assertExists($row['path']);
    Event::assertDispatched(AttachmentSaved::class,
        fn (AttachmentSaved $e) => $e->attachment->id === $row['id']);
});

it('非白名单扩展名 422；超限大小 422', function () {
    Storage::fake('public');
    $token = admin_token();
    $this->withToken($token)->post('/api/admin/attachments',
        ['file' => UploadedFile::fake()->create('evil.svg', 10, 'image/svg+xml')])
        ->assertStatus(422);
    config(['arkadmin.attachment.max_size' => 1]);
    $this->withToken($token)->post('/api/admin/attachments',
        ['file' => UploadedFile::fake()->create('big.jpg', 10, 'image/jpeg')])
        ->assertStatus(422);
});

it('列表分页/keyword ilike/类型过滤；keyword 通配符按字面量', function () { /* 建 3 条：'a.jpg','b%.jpg','c.png'；断言 total、keyword='%.jpg' 只命中 b%.jpg、type=image 排除 png */ });

it('删除：行与文件一起删', function () { /* 上传→DELETE→行无 + Storage::disk 缺失 */ });

it('无 system.attachment.* 权限返回 403 信封', function () { /* plain 账号 index/store/destroy 三端点 code 403 */ });

it('RbacSeeder 种出 attachment 权限且超管拥有；MenuSeeder 种出素材库菜单', function () { /* Permission module=system + MenuService 树含 attachment */ });
```

- [ ] **Step 5: 全量回归 + 提交** — `php artisan test` 全绿（基线 102 + 新增）；`git add -A && git commit -m "feat(m4): framework attachment library with upload api and saved event"`

### Task 2: 框架素材库前端 + 共享选择器（§7.1）

**Files:**
- Create: `admin/src/app/api/attachment.ts`、`admin/src/app/utils/attachment.ts`、`admin/src/app/components/AttachmentPicker.vue`、`admin/src/app/views/attachment/index.vue`、`admin/tests/attachment.test.ts`
- Modify: `admin/src/app/lang/index.ts`（common 增 `upload/copy/copied/choose/preview`）

**Interfaces:**
- Consumes: Task 1 的 `/api/admin/attachments` 三端点；`v-permission` 指令；`t('common.*')`
- Produces:
  - `attachmentApi.list(params): { list: AttachmentRow[]; total: number }`、`upload(file: File): Promise<AttachmentRow>`（FormData，不手设 Content-Type）、`destroy(id)`
  - `AttachmentRow { id,name,path,disk,mime,size,width,height,url,created_at? }`
  - `formatFileSize(bytes: number): string`（B/KB/MB/GB，KB 起一位小数）、`isImage(mime: string): boolean`
  - `<AttachmentPicker v-model="visible" :multiple="false" @confirm="onPick" />`（emit `confirm(rows: AttachmentRow[])`，供 T6 CMS 封面选择复用）

**页面行为规范**（照 `app/views/system/admin/index.vue` 模板写法：el-card + inline 搜索 + v-permission + 分页）：
- `views/attachment/index.vue`：搜索（keyword）；上传按钮（`v-permission="'system.attachment.store'"`，`el-upload :show-file-list="false"` + 自定义 `:http-request` 逐个调 `attachmentApi.upload`）；卡片网格（`el-image` 缩略图 `:preview-src-list=[url]`、名称、`formatFileSize(size)`、mime tag、时间）；操作：复制链接（`navigator.clipboard.writeText(row.url)` + `t('common.copied')`）、删除（`v-permission="'system.attachment.destroy'"` + ElMessageBox.confirm）；`el-pagination`。
- `components/AttachmentPicker.vue`：`el-dialog` 内同样的网格（复用列表逻辑）；`multiple=false` 时点卡片即 `emit('update:modelValue', false)` + `emit('confirm', [row])`；`multiple=true` 时卡片带 checkbox、底部确定批量 emit。上传按钮同素材库页。

- [ ] **Step 1: 写失败测试** `admin/tests/attachment.test.ts`（`formatFileSize`：0→'0 B'、999→'999 B'、1024→'1.0 KB'、1536→'1.5 KB'、1048576→'1.0 MB'、>GB 封顶；`isImage`：'image/png' true、'application/pdf' false）→ `cd admin && npm test` 确认失败
- [ ] **Step 2: 实现** 四个新文件 + lang 增键 → `npm test` 通过
- [ ] **Step 3: 类型检查** — `cd admin && npx vue-tsc -b` 通过
- [ ] **Step 4: 提交** — `git commit -m "feat(m4): admin attachment library page and shared picker component"`

### Task 3: CMS 插件骨架（§6.1/§6.2/§6.3）

**Files:** `addons/cms/` 下 `info.json`、`src/Addon.php`、`src/AddonServiceProvider.php`、`src/Support/CmsException.php`、`src/Models/Category.php`、`src/Models/Article.php`、`database/migrations/2026_09_16_100001_create_cms_categories_table.php`、`database/migrations/2026_09_16_100002_create_cms_articles_table.php`、`database/menus.php`、`database/permissions.php`、`routes/admin.php`（Controller 先建空壳，T4/T5 补方法）；Test: `server/tests/Feature/Addon/CmsLifecycleTest.php`（先写安装冒烟用例）。

**Interfaces:**
- Produces:
  - 表 `cms_categories(id, parent_id(default 0,index), name(64), description(255,''), sort(int 0), is_show(bool true), timestamps)`；表 `cms_articles(id, category_id(default 0,index), title(191), summary(255,''), content(text,''), cover(191,''), tags(jsonb default '[]'), status(smallint 0 草稿/1 已发布), published_at(nullable), timestamps)`
  - 模型：`Category`（casts parent_id/sort/is_show；`children()` hasMany self 按 sort）、`Article`（casts `tags=>'array'`、`status=>'integer'`、`published_at=>'datetime'`；`category()` belongsTo）
  - `Addons\cms\Addon implements Lifecycle`：`install()` 在 `cms_categories`/`cms_articles` 均空时种「默认栏目」+ 1 篇已发布示例文章（示例正文为简单 HTML，验证富文本渲染）；`uninstall()` **空实现**（`--keep-data` 语义：数据清除只由迁移回滚负责，钩子不得删数据）；`enable/disable/upgrade` 空实现
  - 菜单：`cms`(顶级 sort 150) → `cms.category`(`/cms/category`,`view_path=category/index`,perm `addon.cms.category.index`)、`cms.article`(`/cms/article`,`view_path=article/index`,perm `addon.cms.article.index`)
  - `permissions.php`：8 条 `addon.cms.{category,article}.{index,store,update,destroy}`
  - 路由（T4/T5 实现，prefix `/api/admin/addon/cms` 自动挂）：`GET/POST categories`、`PUT/DELETE categories/{id}`、`GET articles`、`GET articles/{id}`、`POST articles`、`PUT/DELETE articles/{id}`
  - `Addons\cms\Support\CmsException extends \RuntimeException`（业务守卫，控制器转 `fail(1,msg)`）

- [ ] **Step 1: 写失败测试**（CmsLifecycleTest 第 1 例）

```php
beforeEach(function () { (new RbacSeeder)->run(); (new MenuSeeder)->run(); });
afterEach(function () { app(AddonManager::class)->flushCompiled(); });

it('安装 cms：表/菜单/权限/前端产物/种子齐备且接口可用', function () {
    app(AddonInstaller::class)->install('cms');
    expect(Schema::hasTable('cms_categories'))->toBeTrue()
        ->and(Schema::hasTable('cms_articles'))->toBeTrue()
        ->and(Category::count())->toBe(1)->and(Article::count())->toBe(1)
        ->and(Menu::where('addon_key', 'cms')->count())->toBe(3)
        ->and(Permission::where('module', 'cms')->count())->toBe(8)
        ->and(menu_tree_names((new MenuService)->treeFor(super_admin())))
        ->toContain('cms', 'cms.category', 'cms.article');
    // 前端产物复制（M3 机制）：TestCase 已隔离 admin_path
    expect(is_file(config('arkadmin.admin_path').'/src/addons/cms/views/article/index.vue'))->toBeTrue();
    $token = admin_token();
    $this->getJson('/api/admin/addon/cms/categories', ['Authorization' => "Bearer {$token}"])
        ->assertOk()->assertJsonPath('code', 0);
});
```

- [ ] **Step 2: 跑失败**（插件不存在）→ **Step 3: 实现**全部骨架文件 → **Step 4: 转绿**
- [ ] **Step 5: 提交** — `git commit -m "feat(m4): cms addon skeleton with schema, menus and permissions"`

### Task 4: CMS 栏目 API（服务厚/控制器瘦）

**Files:** `addons/cms/src/Services/CategoryService.php`、`src/Http/Controllers/CategoryController.php`；Test: 追加 `CmsLifecycleTest.php` 用例。

**Interfaces:**
- Produces:
  - `CategoryService::tree(): array`（管理树：含未显示项、不剪空目录，节点带 `article_count`）
  - `store(array $data): Category`、`update(Category $c, array $data): void`、`destroy(Category $c): void`
  - 守卫（均抛 `CmsException`，控制器 `catch` 后 `fail(1, msg)`）：update 的 `parent_id` 不得是自己或子孙（`descendantIds(Category $c): array`）；destroy 前置「有子栏目」「栏目下有文章」
  - API 形状：`GET categories` → `data` = 树数组 `[{id,parent_id,name,description,sort,is_show,article_count,children:[]}]`；`POST/PUT` 接受 `parent_id,name,description,sort,is_show`（`name` required|max:64，`parent_id` integer|min:0）

- [ ] **Step 1: 写失败测试**（HTTP 层）：建根→建子→树形返回含嵌套与 `article_count=0`；`PUT` 把父级设为自己子孙 → code 1 + msg；`DELETE` 有文章的栏目 → code 1「该栏目下还有文章」；先删文章/子栏目后可删；无 `addon.cms.category.store` 权限 403
- [ ] **Step 2: 跑失败** → **Step 3: 实现** → **Step 4: 转绿**
- [ ] **Step 5: 提交** — `git commit -m "feat(m4): cms category api with tree and delete guards"`

### Task 5: CMS 文章 API（富文本/封面/标签/状态）

**Files:** `addons/cms/src/Services/ArticleService.php`、`src/Http/Requests/ArticleStoreRequest.php`、`src/Http/Requests/ArticleUpdateRequest.php`、`src/Http/Controllers/ArticleController.php`；Test: 追加用例。

**Interfaces:**
- Produces:
  - 校验（FormRequest，镜像框架 AdminStoreRequest/UpdateRequest 分工）：`title` required|max:191（update 为 sometimes）；`category_id` required|integer|`Rule::exists('cms_categories','id')`；`summary` nullable|max:255；`content` nullable|string；`cover` nullable|string|max:191（存 attachments.path）；`tags` nullable|array|max:10 且 `tags.*` string|max:32；`status` required|in:0,1
  - `ArticleService`：`store(array $data): Article`、`update(Article $a, array $data): void`、`destroy(Article $a): void`；normalize：`status=1` 且未给 `published_at` → `now()`，`status=0` → `published_at=null`；`tags` 缺省 `[]`
  - API 形状：`GET articles`（keyword=标题 ilike、category_id、status 过滤；`with('category:id,name')`；行**剔除 content**；分页）；`GET articles/{id}` 全量（编辑用）；`POST/PUT/DELETE`
- Consumes: Task 1 `PgLike::wrap`

- [ ] **Step 1: 写失败测试**：store 带 tags/cover/状态 1 → `published_at` 非空、DB 里 tags 为 jsonb 且读回是数组（**PG jsonb 往返验证，若 insert 报类型错则回退：tags 列改 `text` 存 JSON 字符串并在模型 cast，记录偏差**）；status 0 → published_at null；列表 keyword/分类/状态过滤且无 content 字段、带 category.name；show 返回全量；update 改标题/tags；delete；403
- [ ] **Step 2: 跑失败** → **Step 3: 实现** → **Step 4: 转绿**
- [ ] **Step 5: 提交** — `git commit -m "feat(m4): cms article api with rich content, tags and publish flow"`

### Task 6: CMS 前端（quill 富文本 + 素材消费）

**Files:** `admin/package.json`（+`quill@^2`）、`addons/cms/admin/lang/zh-cn.ts`、`addons/cms/admin/api/{category,article}.ts`、`addons/cms/admin/components/RichEditor.vue`、`addons/cms/admin/views/category/index.vue`、`addons/cms/admin/views/article/index.vue`

**Interfaces:**
- Consumes: T2 `attachmentApi`/`AttachmentPicker`/`formatFileSize`；`@/app/api/request`；`t('cms.*')`（插件命名空间，勿占用 app/login/common）；`v-permission="'addon.cms.*'"`
- API 封装（照 demo `note.ts` 形态）：
  - `categoryApi.list(): CategoryNode[]`（GET `/addon/cms/categories`，`CategoryNode{id,parent_id,name,description,sort,is_show,article_count,children}`）
  - `articleApi.list(params): {list: ArticleRow[]; total}`、`show(id)`、`store(data)`、`update(id,data)`、`destroy(id)`
- `RichEditor.vue`（quill snow 主题）：`v-model`（`modelValue/placeholder` props，`text-change` → emit；watch 回写需防回环标记）；工具栏 `[header] bold italic underline strike [ordered/bullet list] blockquote code-block link image clean`；**image handler**：临时 `<input type=file accept=image/*>` → `attachmentApi.upload(file)` → `quill.insertEmbed(range.index,'image',att.url)`（素材库消费点 ①）
- `views/category/index.vue`（照 `system/menu/index.vue` 树表模板）：列 栏目/描述/排序/显示/文章数/操作；弹窗字段 `parent_id`(el-tree-select，check-strictly)、name、description、sort、is_show；i18n `cms.category.*`
- `views/article/index.vue`（照 `system/admin/index.vue` 列表模板 + 弹窗宽 860px）：搜索 keyword/category/status；列 ID/标题/栏目/标签(tags el-tag)/封面(40px 缩略图)/状态/发布时间/操作；弹窗字段 title、category_id(树选)、summary、tags(el-select multiple filterable allow-create)、**cover（点击打开 `AttachmentPicker` 选素材 → 存 `row.path`，展示 `<img :src="attachmentUrl">`；素材库消费点 ②，另提供清除按钮）**、status(el-switch 0/1)、content(RichEditor)；`attachmentUrl(path)` = `/storage/`+path? **否——封面 img 一律用附件接口返回的 `url` 绝对地址**：选择器返回 `AttachmentRow`，表单同时存 `path`（提交值）与 `coverUrl`（展示用 ref）
- save/remove 均 try/catch（拦截器已弹错，防 unhandled rejection）

- [ ] **Step 1: `cd admin && npm i quill@^2`**（腾讯镜像）；失败则停下报告，不改用未维护的分叉
- [ ] **Step 2: 实现** lang/api/RichEditor/两页面 → `npx vue-tsc -b` 通过（quill 类型异常则 `d.ts` 声明兜底并记录）
- [ ] **Step 3: `npm test` 全绿**（既有 16 + T2 新增）
- [ ] **Step 4: 提交** — `git commit -m "feat(m4): cms admin pages with rich text editor and attachment picker"`

### Task 7: §9 验收流程 1–7 + 文档 + wrap-up

**Files:** `server/tests/Feature/Addon/CmsLifecycleTest.php`（追加用例）、`.gitignore`、`AGENTS.md`、`docs/superpowers/plans/2026-09-16-arkadmin-m4-wrapup.md`

**Interfaces:** Consumes: T3–T6 全部产物。

- [ ] **Step 1: 全回路自动化（§9 步骤 1–6）** — 追加用例：

```php
it('§9-3 权限裁剪：仅授权文章的角色只看到 cms.article，category 接口 403', function () { /* Role admin_cms_editor syncPermissions(['addon.cms.article.index'])；/auth/me 树含 cms+cms.article 无 cms.category；GET /addon/cms/categories code 403；文章 GET code 0 */ });
it('§9-4 disable：菜单消失/路由 404/数据保留', function () { /* install→建数据→disable：Addon enabled=false、Menu 行 3 保留、表保留、remount_routes 后 GET categories 404 信封、菜单树无 cms */ });
it('§9-5 enable：完整恢复', function () { /* disable→enable：菜单树恢复、remount 后接口 code 0、数据还在 */ });
it('§9-6 uninstall：菜单/权限/表/前端产物清除', function () { /* disable→uninstall：Addon 无行、Menu 0、Permission module=cms 0、两表 drop、admin_path/src/addons/cms 不存在 */ });
it('§9-6 --keep-data 保留业务表', function () { /* 建文章→disable→uninstall(keepData:true)：表在且数据在、注册/菜单/权限清除 */ });
it('§9-6b 卸载后可重装', function () { /* install→disable→uninstall→install：种子重灌、菜单 3 行 */ });
```

- [ ] **Step 2: 全量验证** — 后端 `php artisan test`（预期 ≥130 passed）；前端 `npm test`、`npx vue-tsc -b`、`npm run build` 全过
- [ ] **Step 3: dev 环境实装** — `server/.env` 与 `.env.example` 的 `APP_URL` 改 `http://localhost:8080`；`docker compose -f docker/docker-compose.yml exec -u 1000:1000 php sh -c "cd /var/www/server && php artisan storage:link && php artisan addon:install cms"`；`.gitignore` 追加 `/server/public/storage`；curl 验证 `GET /api/admin/attachments`（token 登录）与上传一张图后 `curl -I http://localhost:8080/storage/attachments/...` 200
- [ ] **Step 4: GUI 手工验收（§9 完成标志）** — `cd admin && npm run dev`（5175）：登录 → 侧边栏「内容管理/素材库」→ 素材库上传图片 → CMS 栏目树建「新闻」子栏目 → 新建文章（封面选素材、正文插图、标签、发布）→ 列表/详情渲染 → 权限裁剪账号侧边栏只见文章 → CLI disable/enable/uninstall 循环 + 浏览器复查；截图存 `gui-test-screenshots/m4_*.png`
- [ ] **Step 5: 文档** — AGENTS.md：常用命令补 `php artisan storage:link`（首次）与素材库/CMS 说明；addons 结构行注明 `cms` 为首个业务插件
- [ ] **Step 6: wrap-up 验收记录**（测试数字、GUI 各项、偏差与遗留）+ 最终提交 `docs(m4): acceptance records`

---

## 范围外（显式排除）

- `settings`/`admin_op_logs` 表与 HTTP 插件管理界面（§5.4 其余表、backlog 候选）
- 在线安装/zip 打包、插件升级流程（`upgrade()` 仍为预留接口）
- 多语言目录（仅 zh-cn）、文章浏览量/评论等 CMS 扩展功能
- admin/dist 生产服务与 CI 构建（生产化组，绑定部署里程碑）

## Self-Review 记录

- **Spec 覆盖**：§5.4 attachments → T1；§5.5 `attachment.saved` → T1（T5 文章封面/正文消费该能力）；§6.1–6.3 目录/清单/生命周期 → T3；§6.5 菜单注入清除 → T3/T7（复用 M2 机制，install/uninstall 全覆盖断言）；§6.6 前端同步 → T3 冒烟断言产物文件 + T7 卸载清除；§7.1 attachment 框架页面 → T2；§9 功能 → T4/T5/T6；§9 验收 1–7 → T7 Step 1（1–6 自动化）+ Step 3/4（安装与 GUI 手工）+ Step 1 本身即第 7 条；§10 M4 完成标志 = §9 全部通过。
- **占位符扫描**：T1 Step 4、T7 Step 1 测试意图以注释给全断言目标；标准 CRUD 页面按既有模板 + 精确字段/列/权限/i18n 键清单规范（模板文件路径已给出，不重复贴 200 行同构代码）；无 TBD。
- **类型一致性**：`AttachmentRow.url`（T2）↔ 后端 `$appends['url']`（T1）；CMS cover 存 `path`（T5 校验 max:191 ↔ 迁移 string(191)）；`AttachmentPicker` emit `confirm(rows)` ↔ 文章页 `onPick([row])`；`CmsException` → `fail(1,msg)` HTTP 200 前端 body.code!=0 弹 msg；`formatFileSize/isImage` T2 定义、T6 页面消费；`PgLike::wrap` T1 定义、T1/T5 消费。
