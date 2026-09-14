# ArkAdmin M1（框架骨架）Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 搭建 ArkAdmin 框架骨架——Docker 开发环境、Laravel 12 API、Sanctum 登录、管理员/角色/权限/菜单 CRUD、Vue3 后台 SPA，最终用超管账号完成一次权限受控的 CRUD。

**Architecture:** 工作区根目录即 ArkAdmin git 仓库（server/ 后端 + admin/ 前端 + docker/ 编排），两个参考仓库 fastadmin/、niushop/ 通过 .gitignore 排除在版本控制之外。后端 API-only（无 Blade 页面），响应统一 `{code, data, msg}` 信封；前端 SPA 动态路由由后端菜单接口驱动。

**Tech Stack:** Laravel 12 / PHP 8.3 / PostgreSQL 16 / Sanctum / spatie-permission ^6 ；Vue 3.4+ / TypeScript / Vite / Element Plus / Pinia / vue-router 4 / vue-i18n 9；Docker（php-fpm + nginx + postgres）。

**Spec:** `docs/superpowers/specs/2026-09-14-arkadmin-design.md`（本计划实现其 §10 M1 行：docker 环境、Laravel API 骨架、登录/Token、管理员/角色/权限 CRUD、menus 表与菜单接口、admin SPA 登录+动态路由+核心页面）

## Global Constraints

- PHP 相关命令一律在 Docker 容器内执行（宿主机无 PHP/Composer）；Node 用宿主机 v22
- Docker 镜像一律走国内加速前缀 `docker.1panel.live`（已验证可用）；Composer 用阿里云镜像；npm 用腾讯镜像
- 端口分配（避开已占用端口）：nginx `8080`、postgres `5432`、vite dev `5175`（5173/5174 已被其他项目占用）
- 数据库连接：host `postgres`，库名 `arkadmin`，用户 `arkadmin`，密码 `arkadmin_db`；测试库 `arkadmin_test`
- 后端所有 JSON 响应使用信封：`{"code": 0, "data": {}, "msg": "ok"}`，`code=0` 成功；认证错误 HTTP 401、验证错误 HTTP 422，其余业务错误 HTTP 200 + 非 0 code
- 权限串约定：`system.<controller>.<action>`（如 `system.admin.index`）；管理员认证 guard 名为 `admin`
- 菜单表字段（§5.4）：`id, parent_id, name, title, icon, route_path, view_path, permission, addon_key, sort, is_show`
- 禁止 MySQL 方言习惯（`FIND_IN_SET`、逗号分隔多值），用 PG 原生类型
- **参考仓库 `fastadmin/` 与 `niushop/` 绝不进入 git**（.gitignore 排除）；niushop 只读不改
- 每个任务完成后独立 commit；后端测试跑法：`docker compose -f docker/docker-compose.yml exec php php artisan test`
- Laravel 应用命名空间分层：控制器 `App\Admin\Http\Controllers\*`、模型 `App\Admin\Models\*`、服务 `App\Admin\Services\*`、支撑 `App\Support\*`

---

### Task 1: Git 仓库初始化（排除参考仓库）

**Files:**
- Create: `.gitignore`

**Interfaces:**
- Produces: 工作区根 `/home/gdmax/fastadmin` 成为独立 git 仓库（branch `main`），fastadmin/、niushop/ 被忽略

- [ ] **Step 1: 初始化仓库并写 .gitignore**

```bash
cd /home/gdmax/fastadmin && git init -b main
```

创建 `.gitignore`：

```gitignore
# 参考仓库（不进版本库）
/fastadmin/
/niushop/

# 依赖与产物
node_modules/
vendor/
admin/dist/

# 环境与编辑器
.env
.env.*
!.env.example
.idea/
.vscode/
.DS_Store

# 日志与运行时
*.log
storage/*.key
```

- [ ] **Step 2: 校验忽略规则生效**

Run: `git -C /home/gdmax/fastadmin status --short`
Expected: 仅出现 `?? .gitignore`、`?? AGENTS.md`、`?? docs/`，无 fastadmin/、niushop/

- [ ] **Step 3: 首次提交**

```bash
git add .gitignore AGENTS.md docs/
git commit -m "chore: init arkadmin repo with design docs (reference repos excluded)"
```

（若提示缺 user.name/email：`git config user.name "gdmax" && git config user.email "gdmax@local"` 后重试）

---

### Task 2: Docker 开发环境

**Files:**
- Create: `docker/Dockerfile`
- Create: `docker/php.ini`
- Create: `docker/nginx.conf`
- Create: `docker/docker-compose.yml`

**Interfaces:**
- Produces: 可用服务 `nginx`(宿主 8080→80)、`php`(build)、`postgres`(宿主 5432)；compose 项目名 `arkadmin`；所有容器 volume 挂载工作区根为 `/var/www`

- [ ] **Step 1: 写 PHP 镜像定义 `docker/Dockerfile`**

```dockerfile
FROM docker.1panel.live/library/php:8.3-fpm

# apt 换腾讯云源（Debian bookworm 为 deb822 格式）
RUN sed -i 's#deb.debian.org#mirrors.tencent.com#g; s#security.debian.org#mirrors.tencent.com#g' \
        /etc/apt/sources.list.d/debian.sources || true

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip zip libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql bcmath gd zip opcache \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=docker.1panel.live/library/composer:2 /usr/bin/composer /usr/bin/composer
RUN composer config -g repos.packagist composer https://mirrors.aliyun.com/composer/

COPY php.ini /usr/local/etc/php/conf.d/99-app.ini

RUN usermod -u 1000 www-data && groupmod -g 1000 www-data
WORKDIR /var/www
```

- [ ] **Step 2: 写 `docker/php.ini`**

```ini
date.timezone = Asia/Shanghai
memory_limit = 256M
upload_max_filesize = 50M
post_max_size = 50M
max_execution_time = 60
```

- [ ] **Step 3: 写 `docker/nginx.conf`**

```nginx
server {
    listen 80 default_server;
    server_name _;
    root /var/www/server/public;
    index index.php;

    client_max_body_size 50m;

    location / {
        try_files $uri $uri/ /index.php$is_args$args;
    }

    location ~ \.php$ {
        fastcgi_pass php:9000;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.ht { deny all; }
}
```

- [ ] **Step 4: 写 `docker/docker-compose.yml`**

```yaml
name: arkadmin

services:
  nginx:
    image: docker.1panel.live/library/nginx:1.27-alpine
    ports:
      - "8080:80"
    volumes:
      - ..:/var/www:ro
      - ./nginx.conf:/etc/nginx/conf.d/default.conf:ro
    depends_on:
      - php
    restart: unless-stopped

  php:
    build: .
    volumes:
      - ..:/var/www
    depends_on:
      postgres:
        condition: service_healthy
    restart: unless-stopped

  postgres:
    image: docker.1panel.live/library/postgres:16
    ports:
      - "5432:5432"
    environment:
      POSTGRES_USER: arkadmin
      POSTGRES_PASSWORD: arkadmin_db
      POSTGRES_DB: arkadmin
      TZ: Asia/Shanghai
    volumes:
      - arkadmin-pg-data:/var/lib/postgresql/data
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U arkadmin -d arkadmin"]
      interval: 5s
      timeout: 3s
      retries: 30
    restart: unless-stopped

volumes:
  arkadmin-pg-data:
```

- [ ] **Step 5: 构建并启动，验证三服务健康**

```bash
docker compose -f docker/docker-compose.yml up -d --build
docker compose -f docker/docker-compose.yml ps
docker compose -f docker/docker-compose.yml exec php php -v
```

Expected: php 输出 8.3.x；postgres 状态 healthy；`curl -I http://localhost:8080` 任何响应（404/500 均可，此时还没有 Laravel）

- [ ] **Step 6: Commit**

```bash
git add docker/
git commit -m "feat(m1): docker dev environment (php8.3-fpm/nginx/postgres16)"
```

---

### Task 3: Laravel 骨架与依赖

**Files:**
- Create: `server/`（composer create-project 生成）
- Modify: `server/.env`、`server/phpunit.xml`、`server/routes/api.php`、`server/bootstrap/app.php`

**Interfaces:**
- Produces: Laravel 12 应用；已装 `laravel/sanctum`、`spatie/laravel-permission:^6`；`.env` 连接 PG；测试库 `arkadmin_test` 配置；`api/admin` 前缀路由组就绪

- [ ] **Step 1: 容器内创建 Laravel 项目**

```bash
docker compose -f docker/docker-compose.yml exec php \
  composer create-project laravel/laravel /var/www/server --prefer-dist --no-interaction
```

- [ ] **Step 2: 安装 Sanctum 与 spatie-permission**

```bash
docker compose -f docker/docker-compose.yml exec php sh -c \
  "cd /var/www/server && composer require laravel/sanctum spatie/laravel-permission:^6"
docker compose -f docker/docker-compose.yml exec php sh -c \
  "cd /var/www/server && php artisan install:api"
docker compose -f docker/docker-compose.yml exec php sh -c \
  "cd /var/www/server && php artisan vendor:publish --provider='Spatie\Permission\PermissionServiceProvider'"
```

- [ ] **Step 3: 配置 `server/.env`（数据库段替换为）**

```env
DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=arkadmin
DB_USERNAME=arkadmin
DB_PASSWORD=arkadmin_db
```

- [ ] **Step 4: 创建测试库并在 `server/phpunit.xml` 的 `<php>` 段加入**

```bash
docker compose -f docker/docker-compose.yml exec postgres \
  psql -U arkadmin -d arkadmin -c "CREATE DATABASE arkadmin_test;"
```

```xml
<env name="DB_DATABASE" value="arkadmin_test"/>
```

- [ ] **Step 5: 跑默认迁移与测试，确认 PG 链路通**

```bash
docker compose -f docker/docker-compose.yml exec php sh -c "cd /var/www/server && php artisan migrate"
docker compose -f docker/docker-compose.yml exec php sh -c "cd /var/www/server && php artisan test"
```

Expected: migrate 输出迁移表列表无报错；默认 Pest 用例全部通过

- [ ] **Step 6: Commit**

```bash
git add server/ .gitignore
git commit -m "feat(m1): laravel 12 skeleton with sanctum and spatie-permission"
```

---

### Task 4: 统一响应信封与异常处理

**Files:**
- Create: `server/app/Support/Http/Traits/ApiResponse.php`
- Modify: `server/bootstrap/app.php`
- Test: `server/tests/Feature/EnvelopeTest.php`

**Interfaces:**
- Produces: trait `App\Support\Http\Traits\ApiResponse`：`success($data = null, string $msg = 'ok'): JsonResponse`、`fail(int $code = 1, string $msg = '', $data = null): JsonResponse`、`paginate($p): JsonResponse`（返回 `data.list/total/page/per_page`）；异常统一渲染规则（验证 422 `{code:422,msg,data:errors}`、未认证 401 `{code:401}`、其他 500 `{code:500}`）

- [ ] **Step 1: 写失败测试 `server/tests/Feature/EnvelopeTest.php`**

```php
<?php

use App\Support\Http\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Tests\TestCase;

class EnvelopeDemo
{
    use ApiResponse;
    public function demoPaginate()
    {
        $p = new \Illuminate\Pagination\LengthAwarePaginator([['id' => 1]], 1, 15, 1);
        return $this->paginate($p);
    }
}

it('wraps success data', function () {
    $r = (new class { use ApiResponse; })->success(['x' => 1]);
    expect($r->getStatusCode())->toBe(200)
        ->and($r->getData(true))->toBe(['code' => 0, 'data' => ['x' => 1], 'msg' => 'ok']);
});

it('wraps paginate as list/total', function () {
    $r = (new EnvelopeDemo)->demoPaginate();
    $data = $r->getData(true)['data'];
    expect($data)->toHaveKeys(['list', 'total', 'page', 'per_page'])
        ->and($data['total'])->toBe(1);
});

it('renders validation exception as envelope', function () {
    $resp = $this->postJson('/api/admin/__probe');
    $resp->assertStatus(422)
        ->and($resp->json('code'))->toBe(422)
        ->and($resp->json('msg'))->toBeString();
});
```

并在 `server/routes/api.php` 临时加入探针路由（Task 8 重写该文件时移除）：

```php
Route::prefix('admin')->post('__probe', fn (\Illuminate\Http\Request $r) => $r->validate(['name' => 'required']));
```

- [ ] **Step 2: 跑测试确认失败**

Run: `docker compose -f docker/docker-compose.yml exec php sh -c "cd /var/www/server && php artisan test --filter=Envelope"`
Expected: FAIL，trait 不存在

- [ ] **Step 3: 实现 `server/app/Support/Http/Traits/ApiResponse.php`**

```php
<?php

namespace App\Support\Http\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;

trait ApiResponse
{
    protected function success($data = null, string $msg = 'ok'): JsonResponse
    {
        return response()->json(['code' => 0, 'data' => $data, 'msg' => $msg]);
    }

    protected function fail(int $code = 1, string $msg = '', $data = null): JsonResponse
    {
        return response()->json(['code' => $code, 'data' => $data, 'msg' => $msg]);
    }

    protected function paginate(LengthAwarePaginator $p): JsonResponse
    {
        return $this->success([
            'list' => $p->items(),
            'total' => $p->total(),
            'page' => $p->currentPage(),
            'per_page' => $p->perPage(),
        ]);
    }
}
```

- [ ] **Step 4: 在 `server/bootstrap/app.php` 的 `withExceptions` 回调中加统一渲染**

```php
->withExceptions(function (Exceptions $exceptions) {
    $envelope = fn (int $code, string $msg, $data = null) => response()->json(
        ['code' => $code, 'data' => $data, 'msg' => $msg], $code === 401 ? 401 : ($code === 422 ? 422 : 200)
    );
    $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, $request) use ($envelope) {
        return $envelope(401, $e->getMessage() ?: '未登录或登录已过期');
    });
    $exceptions->render(function (\Illuminate\Validation\ValidationException $e, $request) use ($envelope) {
        return $envelope(422, $e->getMessage(), $e->errors());
    });
    $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e, $request) use ($envelope) {
        return $envelope($e->getStatusCode(), $e->getMessage() ?: '请求错误');
    });
})
```

（保留文件中原有 use 语句；信封的 HTTP 码策略：401→401、422→422、其余 HttpException→200）

- [ ] **Step 5: 跑测试通过并 Commit**

Run: 同 Step 2，Expected: PASS

```bash
git add server/app/Support server/bootstrap/app.php server/tests/Feature/EnvelopeTest.php server/routes/api.php
git commit -m "feat(m1): unified api response envelope and exception rendering"
```

---

### Task 5: admins 表、Admin 模型与认证守卫

**Files:**
- Create: `server/app/Admin/Models/Admin.php`
- Create: `server/database/migrations/xxxx_xx_xx_xx_create_admins_table.php`
- Modify: `server/config/auth.php`
- Modify: spatie 迁移 `..._create_permission_tables.php`（加 module 列）
- Test: `server/tests/Feature/AdminModelTest.php`

**Interfaces:**
- Produces: 模型 `App\Admin\Models\Admin`（HasApiTokens + HasRoles，`$guard_name='admin'`，fillable `username,name,password,status`，password 自动 hashed cast，hidden password）；guard `admin` → provider `admins`；permissions 表含 `module` 列（default `'system'`）

- [ ] **Step 1: 写失败测试**

```php
<?php

use App\Admin\Models\Admin;
use Illuminate\Support\Facades\Hash;

it('creates admin with hashed password', function () {
    $a = Admin::create(['username' => 't1', 'password' => 'secret123', 'name' => 'T', 'status' => 1]);
    expect(Hash::check('secret123', $a->password))->toBeTrue()
        ->and($a->password)->not->toBe('secret123');
});

it('hides password in array', function () {
    $a = Admin::create(['username' => 't2', 'password' => 'secret123', 'status' => 1]);
    expect($a->toArray())->not->toHaveKey('password');
});
```

- [ ] **Step 2: 跑测试确认失败**（Admin 类不存在）

- [ ] **Step 3: 迁移 `database/migrations/2026_09_14_000001_create_admins_table.php`**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admins', function (Blueprint $t) {
            $t->id();
            $t->string('username', 64)->unique();
            $t->string('name', 64)->default('');
            $t->string('password');
            $t->unsignedSmallInteger('status')->default(1); // 1启用 0禁用
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admins');
    }
};
```

- [ ] **Step 4: 模型 `app/Admin/Models/Admin.php`**

```php
<?php

namespace App\Admin\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class Admin extends Authenticatable
{
    use HasApiTokens, HasRoles, Notifiable;

    protected $guard_name = 'admin';

    protected $fillable = ['username', 'name', 'password', 'status'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['password' => 'hashed'];
    }
}
```

- [ ] **Step 5: `config/auth.php` 增加 guard/provider（defaults 保持 web 不变）**

```php
'guards' => [
    // ...原有 web
    'admin' => ['driver' => 'sanctum', 'provider' => 'admins'],
],
'providers' => [
    // ...原有 users
    'admins' => ['driver' => 'eloquent', 'model' => App\Admin\Models\Admin::class],
],
```

- [ ] **Step 6: 编辑 spatie 迁移（`database/migrations/*_create_permission_tables.php`），在 permissions 表定义中 `guard_name` 之后加一行**

```php
$table->string('module', 64)->default('system')->after('guard_name')->index();
```

- [ ] **Step 7: 跑 `php artisan migrate` 与测试，通过后 Commit**

```bash
git add server/app/Admin server/database/migrations server/config/auth.php server/tests/Feature/AdminModelTest.php
git commit -m "feat(m1): admins table, admin model and sanctum guard"
```

---

### Task 6: 超管旁路与种子数据

**Files:**
- Create: `server/app/Admin/Seeds/RbacSeeder.php`（一次性 seeder，不进 DatabaseSeeder 常驻）
- Modify: `server/bootstrap/app.php`（Gate::before）
- Test: `server/tests/Feature/SuperAdminBypassTest.php`

**Interfaces:**
- Produces: 超管旁路——拥有角色 `super_admin` 的 Admin 通过任何 ability；`RbacSeeder::run()` 幂等地创建：角色 `super_admin`（guard admin）、权限 `system.admin.index/store/update/destroy`、`system.role.index/store/update/destroy`、`system.menu.index/store/update/destroy`（module=system）、超管账号 `admin/123456`

- [ ] **Step 1: 写失败测试**

```php
<?php

use App\Admin\Models\Admin;
use App\Admin\Seeds\RbacSeeder;
use Illuminate\Support\Facades\Gate;

it('super admin passes any gate', function () {
    (new RbacSeeder)->run();
    $super = Admin::where('username', 'admin')->first();
    expect(Gate::forUser($super)->allows('system.menu.index'))->toBeTrue();
});

it('seeder is idempotent', function () {
    (new RbacSeeder)->run();
    (new RbacSeeder)->run();
    expect(Admin::where('username', 'admin')->count())->toBe(1)
        ->and(\Spatie\Permission\Models\Role::where('name', 'super_admin')->count())->toBe(1);
});
```

- [ ] **Step 2: 跑测试确认失败**

- [ ] **Step 3: 在 `bootstrap/app.php` 注册 Gate 旁路（`withGate` 部分，若无则加在返回链上）**

```php
->withGate(function (\Illuminate\Contracts\Foundation\Application $app) {
    Gate::before(function ($user, string $ability) {
        return $user instanceof \App\Admin\Models\Admin && $user->hasRole('super_admin') ? true : null;
    });
})
```

顶部补 `use Illuminate\Support\Facades\Gate;`

- [ ] **Step 4: 实现 `app/Admin/Seeds/RbacSeeder.php`**

```php
<?php

namespace App\Admin\Seeds;

use App\Admin\Models\Admin;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RbacSeeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetPermissionsCacheId();

        $matrix = ['admin', 'role', 'menu'];
        foreach ($matrix as $m) {
            foreach (['index', 'store', 'update', 'destroy'] as $act) {
                Permission::firstOrCreate(
                    ['name' => "system.{$m}.{$act}", 'guard_name' => 'admin'],
                    ['module' => 'system']
                );
            }
        }

        $super = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'admin']);
        $super->syncPermissions(Permission::where('guard_name', 'admin')->pluck('name'));

        Admin::firstOrCreate(
            ['username' => 'admin'],
            ['name' => '超级管理员', 'password' => '123456', 'status' => 1]
        )->assignRole($super);
    }
}
```

- [ ] **Step 5: 跑测试通过，生产库执行一次种子，Commit**

```bash
docker compose -f docker/docker-compose.yml exec php sh -c \
  "cd /var/www/server && php artisan tinker --execute='(new App\Admin\Seeds\RbacSeeder)->run();'"
git add server/app/Admin/Seeds server/bootstrap/app.php server/tests/Feature/SuperAdminBypassTest.php
git commit -m "feat(m1): super admin gate bypass and rbac seeder"
```

---

### Task 7: menus 表、模型与种子

**Files:**
- Create: `server/app/Admin/Models/Menu.php`
- Create: `server/database/migrations/2026_09_14_000002_create_menus_table.php`
- Create: `server/app/Admin/Seeds/MenuSeeder.php`
- Test: `server/tests/Feature/MenuModelTest.php`

**Interfaces:**
- Produces: 模型 `App\Admin\Models\Menu`（fillable 全部业务字段）；`MenuSeeder::run()` 幂等创建：控制台（view_path `dashboard/index`）、系统管理（route `/system`，目录型无 view_path）及其子项 管理员（`system/admin/index`，permission `system.admin.index`）、角色（`system/role/index`）、菜单（`system/menu/index`）

- [ ] **Step 1: 写失败测试**

```php
<?php

use App\Admin\Seeds\MenuSeeder;
use App\Admin\Models\Menu;

it('seeds menu tree and is idempotent', function () {
    (new MenuSeeder)->run();
    (new MenuSeeder)->run();
    $dash = Menu::where('name', 'dashboard')->first();
    $sys = Menu::where('name', 'system')->first();
    expect($dash)->not->toBeNull()
        ->and($sys->children()->count())->toBe(3)
        ->and(Menu::where('name', 'system.admin')->first()->permission)->toBe('system.admin.index');
});
```

- [ ] **Step 2: 跑测试确认失败**

- [ ] **Step 3: 迁移 `2026_09_14_000002_create_menus_table.php`**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menus', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('parent_id')->default(0)->index();
            $t->string('name', 64)->unique();
            $t->string('title', 64);
            $t->string('icon', 64)->default('');
            $t->string('route_path', 191)->default('');
            $t->string('view_path', 191)->default('');
            $t->string('permission', 191)->default('')->index();
            $t->string('addon_key', 64)->default('')->index();
            $t->integer('sort')->default(0);
            $t->boolean('is_show')->default(true);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menus');
    }
};
```

- [ ] **Step 4: 模型 `app/Admin/Models/Menu.php`**

```php
<?php

namespace App\Admin\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Menu extends Model
{
    protected $fillable = [
        'parent_id', 'name', 'title', 'icon', 'route_path',
        'view_path', 'permission', 'addon_key', 'sort', 'is_show',
    ];

    protected function casts(): array
    {
        return ['is_show' => 'boolean'];
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort');
    }
}
```

- [ ] **Step 5: 实现 `app/Admin/Seeds/MenuSeeder.php`**

```php
<?php

namespace App\Admin\Seeds;

use App\Admin\Models\Menu;

class MenuSeeder
{
    public function run(): void
    {
        $upsert = function (array $attrs) {
            Menu::updateOrCreate(['name' => $attrs['name']], $attrs);
        };

        $upsert([
            'name' => 'dashboard', 'title' => '控制台', 'icon' => 'Odometer',
            'route_path' => '/dashboard', 'view_path' => 'dashboard/index',
            'sort' => 0, 'is_show' => true, 'addon_key' => '', 'permission' => '',
        ]);
        $upsert([
            'name' => 'system', 'title' => '系统管理', 'icon' => 'Setting',
            'route_path' => '/system', 'view_path' => '', 'sort' => 100,
            'is_show' => true, 'addon_key' => '', 'permission' => '',
        ]);
        $sysId = Menu::where('name', 'system')->value('id');
        foreach ([
            ['name' => 'system.admin', 'title' => '管理员管理', 'icon' => 'User',
             'route_path' => '/system/admin', 'view_path' => 'system/admin/index',
             'permission' => 'system.admin.index', 'sort' => 1],
            ['name' => 'system.role', 'title' => '角色管理', 'icon' => 'Avatar',
             'route_path' => '/system/role', 'view_path' => 'system/role/index',
             'permission' => 'system.role.index', 'sort' => 2],
            ['name' => 'system.menu', 'title' => '菜单管理', 'icon' => 'Menu',
             'route_path' => '/system/menu', 'view_path' => 'system/menu/index',
             'permission' => 'system.menu.index', 'sort' => 3],
        ] as $item) {
            $upsert($item + ['parent_id' => $sysId, 'icon' => $item['icon'],
                'is_show' => true, 'addon_key' => '', 'permission' => $item['permission']]);
        }
    }
}
```

- [ ] **Step 6: 跑测试通过，生产库执行种子，Commit**

```bash
docker compose -f docker/docker-compose.yml exec php sh -c \
  "cd /var/www/server && php artisan tinker --execute='(new App\Admin\Seeds\MenuSeeder)->run();'"
git add server/app/Admin server/database/migrations server/tests/Feature/MenuModelTest.php
git commit -m "feat(m1): menus table, model and menu seeder"
```

---

### Task 8: 认证三接口与菜单裁剪

**Files:**
- Create: `server/app/Admin/Services/MenuService.php`
- Create: `server/app/Admin/Http/Controllers/AuthController.php`
- Modify: `server/routes/api.php`（整体重写，移除 Task 4 探针）
- Test: `server/tests/Feature/AuthTest.php`、`server/tests/Feature/MenuFilterTest.php`

**Interfaces:**
- Produces: 
  - `MenuService::treeFor(Admin $admin): array`（按权限裁剪 + 剔除空目录 + 嵌套 children）
  - `POST /api/admin/auth/login` → `{token, admin:{id,username,name}}`；失败 `{code:1,msg:'用户名或密码错误'}`（HTTP 200）
  - `GET /api/admin/auth/me` → `{admin, roles:[], permissions:[], menus:[]}`
  - `DELETE /api/admin/auth/logout` → 注销当前 token

- [ ] **Step 1: 写失败测试 `tests/Feature/AuthTest.php`**

```php
<?php

use App\Admin\Models\Admin;
use App\Admin\Seeds\RbacSeeder;

beforeEach(fn () => (new RbacSeeder)->run());

it('logs in with correct credentials', function () {
    $r = $this->postJson('/api/admin/auth/login', ['username' => 'admin', 'password' => '123456']);
    $r->assertOk()->assertJsonPath('code', 0)
        ->and($r->json('data.token'))->toBeString();
});

it('rejects wrong password with envelope', function () {
    $r = $this->postJson('/api/admin/auth/login', ['username' => 'admin', 'password' => 'nope']);
    $r->assertOk()->assertJsonPath('code', 1);
});

it('me returns menus and permissions for super admin', function () {
    $token = $this->postJson('/api/admin/auth/login', ['username' => 'admin', 'password' => '123456'])
        ->json('data.token');
    $r = $this->withToken($token)->getJson('/api/admin/auth/me');
    $r->assertOk()
        ->and(collect($r->json('data.permissions')))->toContain('system.admin.index')
        ->and(collect($r->json('data.menus'))->pluck('name'))->toContain('system');
});

it('logout revokes token', function () {
    $token = $this->postJson('/api/admin/auth/login', ['username' => 'admin', 'password' => '123456'])
        ->json('data.token');
    $this->withToken($token)->deleteJson('/api/admin/auth/logout')->assertOk();
    $this->withToken($token)->getJson('/api/admin/auth/me')->assertStatus(401);
});
```

- [ ] **Step 2: 写失败测试 `tests/Feature/MenuFilterTest.php`**

```php
<?php

use App\Admin\Models\Admin;
use App\Admin\Seeds\MenuSeeder;
use App\Admin\Seeds\RbacSeeder;
use App\Admin\Services\MenuService;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    (new RbacSeeder)->run();
    (new MenuSeeder)->run();
});

it('super admin sees all menus', function () {
    $tree = (new MenuService)->treeFor(Admin::where('username', 'admin')->first());
    expect(collect($tree)->pluck('name'))->toContain('dashboard', 'system');
});

it('admin without permission sees no system menus', function () {
    $u = Admin::create(['username' => 'noperm', 'password' => 'x123456', 'status' => 1]);
    $tree = (new MenuService)->treeFor($u);
    expect(collect($tree)->pluck('name'))->toContain('dashboard')
        ->and(collect($tree)->pluck('name'))->not->toContain('system');
});

it('admin with role permission sees only permitted children', function () {
    $u = Admin::create(['username' => 'half', 'password' => 'x123456', 'status' => 1]);
    $role = Role::create(['name' => 'admin_mgr', 'guard_name' => 'admin']);
    $role->syncPermissions(['system.admin.index', 'system.admin.store']);
    $u->assignRole($role);

    $tree = (new MenuService)->treeFor($u);
    $sys = collect($tree)->firstWhere('name', 'system');
    expect($sys)->not->toBeNull()
        ->and(collect($sys['children'])->pluck('name'))->toEqual(['system.admin']);
});
```

- [ ] **Step 3: 跑测试确认全部失败**

- [ ] **Step 4: 实现 `app/Admin/Services/MenuService.php`**

```php
<?php

namespace App\Admin\Services;

use App\Admin\Models\Admin;
use App\Admin\Models\Menu;
use Illuminate\Support\Collection;

class MenuService
{
    /** 菜单树（含无权限过滤），结构字段与 menus 表一致 + children */
    public function treeFor(Admin $admin): array
    {
        $perms = null;
        if (!$admin->hasRole('super_admin')) {
            $perms = $admin->getAllPermissions()->pluck('name')->flip();
        }

        $visible = Menu::orderBy('sort')->get()
            ->filter(fn (Menu $m) => $m->is_show)
            ->filter(fn (Menu $m) => $perms === null || $m->permission === '' || $perms->has($m->permission))
            ->values();

        return $this->nest($visible, 0);
    }

    protected function nest(Collection $nodes, int $parentId): array
    {
        $out = [];
        foreach ($nodes->where('parent_id', $parentId) as $n) {
            $children = $this->nest($nodes, $n->id);
            // 目录型（无 view_path）且无可见子项则剔除
            if ($n->view_path === '' && !$children) {
                continue;
            }
            $out[] = [
                'id' => $n->id, 'parent_id' => $n->parent_id, 'name' => $n->name,
                'title' => $n->title, 'icon' => $n->icon, 'route_path' => $n->route_path,
                'view_path' => $n->view_path, 'permission' => $n->permission,
                'addon_key' => $n->addon_key, 'sort' => $n->sort,
                'children' => $children,
            ];
        }
        return $out;
    }
}
```

- [ ] **Step 5: 实现 `app/Admin/Http/Controllers/AuthController.php`**

```php
<?php

namespace App\Admin\Http\Controllers;

use App\Admin\Models\Admin;
use App\Admin\Services\MenuService;
use App\Support\Http\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    use ApiResponse;

    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        $admin = Admin::where('username', $request->input('username'))->first();
        if (!$admin || !Hash::check($request->input('password'), $admin->password)) {
            return $this->fail(1, '用户名或密码错误');
        }
        if ($admin->status !== 1) {
            return $this->fail(1, '账号已禁用');
        }

        $token = $admin->createToken('admin')->plainTextToken;
        return $this->success([
            'token' => $token,
            'admin' => ['id' => $admin->id, 'username' => $admin->username, 'name' => $admin->name],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var Admin $admin */
        $admin = $request->user('admin');
        return $this->success([
            'admin' => ['id' => $admin->id, 'username' => $admin->username, 'name' => $admin->name],
            'roles' => $admin->getRoleNames(),
            'permissions' => $admin->hasRole('super_admin')
                ? ['*']
                : $admin->getAllPermissions()->pluck('name'),
            'menus' => (new MenuService)->treeFor($admin),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user('admin')->currentAccessToken()->delete();
        return $this->success();
    }
}
```

- [ ] **Step 6: 重写 `routes/api.php`**

```php
<?php

use App\Admin\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

    Route::middleware('auth:admin')->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::delete('auth/logout', [AuthController::class, 'logout']);
    });
});
```

- [ ] **Step 7: 跑全部测试通过，Commit**

```bash
git add server/app/Admin server/routes/api.php server/tests/Feature
git commit -m "feat(m1): auth endpoints with permission-trimmed menu tree"
```

---

### Task 9: 管理员 CRUD

**Files:**
- Create: `server/app/Admin/Http/Controllers/AdminController.php`
- Create: `server/app/Admin/Http/Requests/AdminStoreRequest.php`、`AdminUpdateRequest.php`
- Modify: `server/routes/api.php`、`server/bootstrap/app.php`（permission 中间件别名）
- Test: `server/tests/Feature/AdminCrudTest.php`

**Interfaces:**
- Consumes: ApiResponse trait（Task 4）、`auth:admin` 组（Task 8）
- Produces: `GET/POST/PUT/DELETE /api/admin/admins`（index 支持 `username`/`name` 模糊过滤 + 分页参数 `page`；store/update 接受 `roles` 数组同步角色；update 传空密码则不改密码）

- [ ] **Step 1: 写失败测试**

```php
<?php

use App\Admin\Models\Admin;
use App\Admin\Seeds\RbacSeeder;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    (new RbacSeeder)->run();
    $this->token = $this->postJson('/api/admin/auth/login', ['username' => 'admin', 'password' => '123456'])
        ->json('data.token');
});

function adminHeaders($t) { return ['Authorization' => "Bearer {$t}"]; }

it('lists admins paginated', function () {
    Admin::create(['username' => 'u1', 'password' => 'x123456', 'status' => 1]);
    $r = $this->withToken($this->token)->getJson('/api/admin/admins?username=u1');
    $r->assertOk()
        ->and(collect($r->json('data.list'))->pluck('username'))->toContain('u1')
        ->and($r->json('data.total'))->toBeGreaterThanOrEqual(1);
});

it('stores admin with roles', function () {
    $rid = Role::where('name', 'super_admin')->first()->id;
    $r = $this->withToken($this->token)->postJson('/api/admin/admins', [
        'username' => 'new1', 'password' => 'pass123', 'name' => '新管理员', 'status' => 1, 'roles' => [$rid],
    ]);
    $r->assertOk();
    expect(Admin::where('username', 'new1')->first()->hasRole('super_admin'))->toBeTrue();
});

it('rejects duplicate username with 422', function () {
    $this->withToken($this->token)->postJson('/api/admin/admins', [
        'username' => 'admin', 'password' => 'pass123', 'status' => 1,
    ])->assertStatus(422);
});

it('update keeps password when empty', function () {
    $id = Admin::where('username', 'admin')->first()->id;
    $this->withToken($this->token)->putJson("/api/admin/admins/{$id}", ['name' => '改个名', 'status' => 1])
        ->assertOk();
    expect(Admin::find($id)->name)->toBe('改个名');
});

it('cannot delete self', function () {
    $id = Admin::where('username', 'admin')->first()->id;
    $this->withToken($this->token)->deleteJson("/api/admin/admins/{$id}")
        ->assertOk()->assertJsonPath('code', 1);
});
```

- [ ] **Step 2: 跑测试确认失败**

- [ ] **Step 3: `bootstrap/app.php` 注册中间件别名（withMiddleware 回调内）**

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->alias([
        'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
    ]);
})
```

- [ ] **Step 4: 实现 FormRequest `AdminStoreRequest.php`**

```php
<?php

namespace App\Admin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AdminStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'username' => 'required|string|max:64|unique:admins,username',
            'name' => 'nullable|string|max:64',
            'password' => 'required|string|min:6|max:64',
            'status' => 'required|integer|in:0,1',
            'roles' => 'nullable|array',
            'roles.*' => 'integer|exists:roles,id',
        ];
    }
}
```

`AdminUpdateRequest.php`：

```php
<?php

namespace App\Admin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'max:64', Rule::unique('admins', 'username')->ignore($this->route('admin'))],
            'name' => 'nullable|string|max:64',
            'password' => 'nullable|string|min:6|max:64',
            'status' => 'required|integer|in:0,1',
            'roles' => 'nullable|array',
            'roles.*' => 'integer|exists:roles,id',
        ];
    }
}
```

- [ ] **Step 5: 实现 `AdminController.php`**

```php
<?php

namespace App\Admin\Http\Controllers;

use App\Admin\Http\Requests\AdminStoreRequest;
use App\Admin\Http\Requests\AdminUpdateRequest;
use App\Admin\Models\Admin;
use App\Support\Http\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Spatie\Permission\Models\Role;

class AdminController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $q = Admin::query()
            ->when($request->filled('username'), fn ($q) => $q->where('username', 'ilike', '%' . $request->input('username') . '%'))
            ->when($request->filled('name'), fn ($q) => $q->where('name', 'ilike', '%' . $request->input('name') . '%'))
            ->orderByDesc('id');

        return $this->paginate($q->paginate((int) $request->input('per_page', 15)));
    }

    public function store(AdminStoreRequest $request): JsonResponse
    {
        $data = $request->validated();
        $roles = Role::where('guard_name', 'admin')->whereIn('id', $data['roles'] ?? [])->get();
        $admin = Admin::create($data);
        $admin->syncRoles($roles);
        return $this->success(['id' => $admin->id], '创建成功');
    }

    public function update(AdminUpdateRequest $request, int $admin): JsonResponse
    {
        $model = Admin::findOrFail($admin);
        $data = $request->validated();
        if (empty($data['password'])) {
            unset($data['password']);
        }
        $model->update($data);
        $roles = Role::where('guard_name', 'admin')->whereIn('id', $data['roles'] ?? [])->get();
        $model->syncRoles($roles);
        return $this->success(null, '更新成功');
    }

    public function destroy(int $admin): JsonResponse
    {
        $model = Admin::findOrFail($admin);
        $me = request()->user('admin');
        if ($model->id === $me->id) {
            return $this->fail(1, '不能删除当前登录账号');
        }
        if ($model->hasRole('super_admin') && Admin::role('super_admin')->count() === 1) {
            return $this->fail(1, '不能删除最后一个超级管理员');
        }
        $model->delete();
        return $this->success(null, '删除成功');
    }
}
```

（`Admin::role(...)` 需要 HasRoles trait 自带 scope，可直接用）

- [ ] **Step 6: 路由（Task 8 文件的 auth 组内追加）**

```php
Route::get('admins', [AdminController::class, 'index'])->middleware('permission:system.admin.index');
Route::post('admins', [AdminController::class, 'store'])->middleware('permission:system.admin.store');
Route::put('admins/{admin}', [AdminController::class, 'update'])->middleware('permission:system.admin.update');
Route::delete('admins/{admin}', [AdminController::class, 'destroy'])->middleware('permission:system.admin.destroy');
```

顶部补 `use App\Admin\Http\Controllers\AdminController;`

- [ ] **Step 7: 跑测试通过，Commit**

```bash
git add server/app/Admin server/routes/api.php server/bootstrap/app.php server/tests/Feature/AdminCrudTest.php
git commit -m "feat(m1): admin crud with role sync and permission middleware"
```

---

### Task 10: 角色 CRUD 与权限分配

**Files:**
- Create: `server/app/Admin/Http/Controllers/RoleController.php`
- Modify: `server/routes/api.php`
- Test: `server/tests/Feature/RoleCrudTest.php`

**Interfaces:**
- Produces: `GET /api/admin/roles`（列表，含 `permissions` 名数组）、`GET /api/admin/roles/permissions`（全部可选权限，按 module 分组）、`POST/PUT/DELETE /api/admin/roles`；body 接受 `name` + `permissions:[]`（权限名数组）

- [ ] **Step 1: 写失败测试**

```php
<?php

use App\Admin\Seeds\RbacSeeder;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    (new RbacSeeder)->run();
    $this->token = $this->postJson('/api/admin/auth/login', ['username' => 'admin', 'password' => '123456'])
        ->json('data.token');
});

it('lists roles with permissions', function () {
    $r = $this->withToken($this->token)->getJson('/api/admin/roles');
    $r->assertOk();
    $super = collect($r->json('data.list') ?? $r->json('data'))->firstWhere('name', 'super_admin');
    expect($super)->not->toBeNull();
});

it('creates role with permissions', function () {
    $r = $this->withToken($this->token)->postJson('/api/admin/roles', [
        'name' => 'editor', 'permissions' => ['system.admin.index'],
    ]);
    $r->assertOk();
    expect(Role::findByName('editor', 'admin')->hasPermissionTo('system.admin.index'))->toBeTrue();
});

it('cannot delete super_admin', function () {
    $id = Role::findByName('super_admin', 'admin')->id;
    $this->withToken($this->token)->deleteJson("/api/admin/roles/{$id}")
        ->assertOk()->assertJsonPath('code', 1);
});

it('cannot update super_admin name', function () {
    $id = Role::findByName('super_admin', 'admin')->id;
    $this->withToken($this->token)->putJson("/api/admin/roles/{$id}", ['name' => 'hax'])
        ->assertOk()->assertJsonPath('code', 1);
});
```

- [ ] **Step 2: 跑测试确认失败**

- [ ] **Step 3: 实现 `RoleController.php`**

```php
<?php

namespace App\Admin\Http\Controllers;

use App\Support\Http\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    use ApiResponse;

    public function index(): JsonResponse
    {
        $roles = Role::where('guard_name', 'admin')->with('permissions')->orderBy('id')->get()
            ->map(fn (Role $r) => [
                'id' => $r->id, 'name' => $r->name,
                'permissions' => $r->permissions->pluck('name'),
                'created_at' => $r->created_at?->toDateTimeString(),
            ]);
        return $this->success($roles);
    }

    public function permissions(): JsonResponse
    {
        $list = Permission::where('guard_name', 'admin')->orderBy('name')->get(['name', 'module'])
            ->map(fn ($p) => ['name' => $p->name, 'module' => $p->module]);
        return $this->success($list->groupBy('module'));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:64|unique:roles,name',
            'permissions' => 'nullable|array',
            'permissions.*' => 'string|exists:permissions,name',
        ]);
        $role = Role::create(['name' => $data['name'], 'guard_name' => 'admin']);
        $role->syncPermissions($data['permissions'] ?? []);
        return $this->success(['id' => $role->id], '创建成功');
    }

    public function update(Request $request, int $role): JsonResponse
    {
        $model = Role::where('guard_name', 'admin')->findOrFail($role);
        if ($model->name === 'super_admin') {
            return $this->fail(1, '超级管理员角色不可修改');
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:64', Rule::unique('roles', 'name')->ignore($model->id)],
            'permissions' => 'nullable|array',
            'permissions.*' => 'string|exists:permissions,name',
        ]);
        $model->update(['name' => $data['name']]);
        $model->syncPermissions($data['permissions'] ?? []);
        return $this->success(null, '更新成功');
    }

    public function destroy(int $role): JsonResponse
    {
        $model = Role::where('guard_name', 'admin')->findOrFail($role);
        if ($model->name === 'super_admin') {
            return $this->fail(1, '超级管理员角色不可删除');
        }
        $model->users()->detach();
        $model->delete();
        return $this->success(null, '删除成功');
    }
}
```

- [ ] **Step 4: 路由（auth 组内追加）**

```php
Route::get('roles', [RoleController::class, 'index'])->middleware('permission:system.role.index');
Route::get('roles/permissions', [RoleController::class, 'permissions'])->middleware('permission:system.role.index');
Route::post('roles', [RoleController::class, 'store'])->middleware('permission:system.role.store');
Route::put('roles/{role}', [RoleController::class, 'update'])->middleware('permission:system.role.update');
Route::delete('roles/{role}', [RoleController::class, 'destroy'])->middleware('permission:system.role.destroy');
```

顶部补 `use App\Admin\Http\Controllers\RoleController;`

- [ ] **Step 5: 跑测试通过，Commit**

```bash
git add server/app/Admin server/routes/api.php server/tests/Feature/RoleCrudTest.php
git commit -m "feat(m1): role crud with permission assignment"
```

---

### Task 11: 菜单 CRUD

**Files:**
- Create: `server/app/Admin/Http/Controllers/MenuController.php`
- Modify: `server/routes/api.php`
- Test: `server/tests/Feature/MenuCrudTest.php`

**Interfaces:**
- Produces: `GET /api/admin/menus`（管理用完整树，不做权限过滤）、`POST/PUT/DELETE /api/admin/menus`；字段与 menus 表一致；store/update 校验 parent 不能指向自己/后代

- [ ] **Step 1: 写失败测试**

```php
<?php

use App\Admin\Models\Menu;
use App\Admin\Seeds\MenuSeeder;
use App\Admin\Seeds\RbacSeeder;

beforeEach(function () {
    (new RbacSeeder)->run();
    (new MenuSeeder)->run();
    $this->token = $this->postJson('/api/admin/auth/login', ['username' => 'admin', 'password' => '123456'])
        ->json('data.token');
});

it('returns full menu tree for management', function () {
    $r = $this->withToken($this->token)->getJson('/api/admin/menus');
    $r->assertOk()
        ->and(collect($r->json('data'))->pluck('name'))->toContain('dashboard', 'system');
});

it('creates a child menu', function () {
    $pid = Menu::where('name', 'system')->first()->id;
    $r = $this->withToken($this->token)->postJson('/api/admin/menus', [
        'parent_id' => $pid, 'name' => 'system.log', 'title' => '操作日志',
        'icon' => 'Document', 'route_path' => '/system/log', 'view_path' => 'system/log/index',
        'permission' => '', 'sort' => 9, 'is_show' => true,
    ]);
    $r->assertOk()->and(Menu::where('name', 'system.log'))->not->toBeNull();
});

it('rejects self as parent', function () {
    $id = Menu::where('name', 'system')->first()->id;
    $this->withToken($this->token)->putJson("/api/admin/menus/{$id}", [
        'parent_id' => $id, 'name' => 'system', 'title' => '系统管理', 'sort' => 100, 'is_show' => true,
        'icon' => 'Setting', 'route_path' => '/system', 'view_path' => '', 'permission' => '',
    ])->assertOk()->assertJsonPath('code', 1);
});

it('deletes menu and its children', function () {
    $id = Menu::where('name', 'system')->first()->id;
    $this->withToken($this->token)->deleteJson("/api/admin/menus/{$id}")->assertOk();
    expect(Menu::where('name', 'like', 'system%')->count())->toBe(0);
});
```

- [ ] **Step 2: 跑测试确认失败**

- [ ] **Step 3: 实现 `MenuController.php`**

```php
<?php

namespace App\Admin\Http\Controllers;

use App\Admin\Models\Menu;
use App\Support\Http\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class MenuController extends Controller
{
    use ApiResponse;

    public function index(): JsonResponse
    {
        $all = Menu::orderBy('sort')->get();
        return $this->success($this->nest($all, 0));
    }

    protected function nest($nodes, int $parentId): array
    {
        return $nodes->where('parent_id', $parentId)->map(function (Menu $m) use ($nodes) {
            return [
                'id' => $m->id, 'parent_id' => $m->parent_id, 'name' => $m->name,
                'title' => $m->title, 'icon' => $m->icon, 'route_path' => $m->route_path,
                'view_path' => $m->view_path, 'permission' => $m->permission,
                'addon_key' => $m->addon_key, 'sort' => $m->sort, 'is_show' => $m->is_show,
                'children' => $this->nest($nodes, $m->id),
            ];
        })->values()->all();
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $menu = Menu::create($data);
        return $this->success(['id' => $menu->id], '创建成功');
    }

    public function update(Request $request, int $menu): JsonResponse
    {
        $model = Menu::findOrFail($menu);
        $data = $this->validated($request, $model->id);
        if (!$this->parentAllowed($model, (int) $data['parent_id'])) {
            return $this->fail(1, '父级菜单不能是自己或自己的子菜单');
        }
        $model->update($data);
        return $this->success(null, '更新成功');
    }

    public function destroy(int $menu): JsonResponse
    {
        $model = Menu::findOrFail($menu);
        foreach ($model->children()->get() as $child) {
            $this->deleteRecursive($child);
        }
        $model->delete();
        return $this->success(null, '删除成功');
    }

    protected function deleteRecursive(Menu $m): void
    {
        foreach ($m->children()->get() as $child) {
            $this->deleteRecursive($child);
        }
        $m->delete();
    }

    protected function parentAllowed(Menu $self, int $parentId): bool
    {
        if ($self->id === $parentId) {
            return false;
        }
        $cur = Menu::find($parentId);
        while ($cur) {
            if ($cur->id === $self->id) {
                return false;
            }
            $cur = $cur->parent_id ? Menu::find($cur->parent_id) : null;
        }
        return true;
    }

    protected function validated(Request $request, ?int $ignoreId = null): array
    {
        $unique = $ignoreId
            ? \Illuminate\Validation\Rule::unique('menus', 'name')->ignore($ignoreId)
            : 'unique:menus,name';
        return $request->validate([
            'parent_id' => 'nullable|integer|exists:menus,id',
            'name' => ['required', 'string', 'max:64', $unique],
            'title' => 'required|string|max:64',
            'icon' => 'nullable|string|max:64',
            'route_path' => 'nullable|string|max:191',
            'view_path' => 'nullable|string|max:191',
            'permission' => 'nullable|string|max:191',
            'sort' => 'nullable|integer',
            'is_show' => 'nullable|boolean',
        ]) + ['parent_id' => (int) $request->input('parent_id', 0)];
    }
}
```

- [ ] **Step 4: 路由（auth 组内追加）**

```php
Route::get('menus', [MenuController::class, 'index'])->middleware('permission:system.menu.index');
Route::post('menus', [MenuController::class, 'store'])->middleware('permission:system.menu.store');
Route::put('menus/{menu}', [MenuController::class, 'update'])->middleware('permission:system.menu.update');
Route::delete('menus/{menu}', [MenuController::class, 'destroy'])->middleware('permission:system.menu.destroy');
```

顶部补 `use App\Admin\Http\Controllers\MenuController;`

- [ ] **Step 5: 跑测试通过，Commit**

```bash
git add server/app/Admin server/routes/api.php server/tests/Feature/MenuCrudTest.php
git commit -m "feat(m1): menu crud with tree management"
```

---

### Task 12: admin SPA 脚手架与请求层

**Files:**
- Create: `admin/`（Vite 脚手架）、`admin/.npmrc`、`admin/vite.config.ts`、`admin/src/main.ts`、`admin/src/env.d.ts`
- Create: `admin/src/app/api/request.ts`、`auth.ts`
- Create: `admin/src/app/stores/user.ts`
- Create: `admin/src/app/lang/index.ts`

**Interfaces:**
- Produces: axios 实例（baseURL `/api/admin`，token 头 `Authorization: Bearer`，拦截器：`code!==0` → ElMessage.error 并 reject；401 → 清 token 跳 `/login`）；`authApi.login/logout/me`；Pinia user store（state：`token/adminInfo/permissions/menus`，actions：`login()/fetchMe()/logout()`，token 持久化 localStorage key `ark_token`）

- [ ] **Step 1: 宿主机创建 Vite + Vue3 + TS 项目并装依赖**

```bash
cd /home/gdmax/fastadmin
npm create vite@latest admin -- --template vue-ts
cd admin && npm install
npm install element-plus @element-plus/icons-vue pinia vue-router@4 axios vue-i18n@9
npm install -D sass
```

写 `admin/.npmrc`：

```ini
registry=https://mirrors.tencent.com/npm/
```

- [ ] **Step 2: `admin/vite.config.ts`（整体替换）**

```ts
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
  plugins: [vue()],
  server: {
    port: 5175,
    proxy: {
      '/api': { target: 'http://localhost:8080', changeOrigin: true },
    },
  },
  resolve: {
    alias: { '@': '/src' },
  },
})
```

- [ ] **Step 3: `admin/src/main.ts`（整体替换）**

```ts
import { createApp } from 'vue'
import { createPinia } from 'pinia'
import ElementPlus from 'element-plus'
import zhCn from 'element-plus/es/locale/lang/zh-cn'
import 'element-plus/dist/index.css'
import App from './App.vue'
import router from './app/router'
import { permissionDirective } from './app/utils/permission'

const app = createApp(App)
app.use(createPinia())
app.use(router)
app.use(ElementPlus, { locale: zhCn })
app.directive('permission', permissionDirective)
app.mount('#app')
```

（router 与指令在 Task 13 创建；本任务先创建占位文件让 dev server 可启动：`admin/src/app/router/index.ts` 导出空 router，`admin/src/app/utils/permission.ts` 导出空对象指令——Task 13 会重写它们为真实实现）

- [ ] **Step 4: `admin/src/app/api/request.ts`**

```ts
import axios from 'axios'
import { ElMessage } from 'element-plus'

export const TOKEN_KEY = 'ark_token'

const request = axios.create({ baseURL: '/api/admin', timeout: 15000 })

request.interceptors.request.use((config) => {
  const token = localStorage.getItem(TOKEN_KEY)
  if (token) config.headers.Authorization = `Bearer ${token}`
  return config
})

request.interceptors.response.use(
  (resp) => {
    const body = resp.data
    if (body.code !== 0) {
      ElMessage.error(body.msg || '请求失败')
      return Promise.reject(new Error(body.msg))
    }
    return body.data
  },
  (err) => {
    if (err.response?.status === 401) {
      localStorage.removeItem(TOKEN_KEY)
      if (location.pathname !== '/login') location.href = '/login'
    } else if (err.response?.status === 422) {
      const msg: string = err.response.data?.msg || '参数错误'
      ElMessage.error(msg)
    } else {
      ElMessage.error(err.response?.data?.msg || '网络错误')
    }
    return Promise.reject(err)
  },
)

export default request
```

- [ ] **Step 5: `admin/src/app/api/auth.ts`**

```ts
import request from './request'

export interface LoginResp { token: string; admin: { id: number; username: string; name: string } }
export interface MeResp {
  admin: { id: number; username: string; name: string }
  roles: string[]
  permissions: string[]
  menus: MenuItem[]
}
export interface MenuItem {
  id: number; parent_id: number; name: string; title: string; icon: string
  route_path: string; view_path: string; permission: string; addon_key: string
  sort: number; children: MenuItem[]
}

export const authApi = {
  login: (data: { username: string; password: string }) =>
    request.post<never, LoginResp>('/auth/login', data),
  me: () => request.get<never, MeResp>('/auth/me'),
  logout: () => request.delete<never, null>('/auth/logout'),
}
```

- [ ] **Step 6: `admin/src/app/stores/user.ts`**

```ts
import { defineStore } from 'pinia'
import { authApi, type MenuItem } from '../api/auth'
import { TOKEN_KEY } from '../api/request'

export const useUserStore = defineStore('user', {
  state: () => ({
    token: localStorage.getItem(TOKEN_KEY) || '',
    adminInfo: null as MeResp['admin'] | null,
    permissions: [] as string[],
    menus: [] as MenuItem[],
    routesAdded: false,
  }),
  getters: {
    isSuper: (s) => s.permissions.includes('*'),
    has: (s) => (perm: string) => s.permissions.includes('*') || s.permissions.includes(perm),
  },
  actions: {
    async login(username: string, password: string) {
      const resp = await authApi.login({ username, password })
      this.token = resp.token
      localStorage.setItem(TOKEN_KEY, resp.token)
    },
    async fetchMe() {
      const resp = await authApi.me()
      this.adminInfo = resp.admin
      this.permissions = resp.permissions
      this.menus = resp.menus
    },
    async logout() {
      try { await authApi.logout() } finally {
        this.token = ''
        this.permissions = []
        this.menus = []
        this.routesAdded = false
        localStorage.removeItem(TOKEN_KEY)
      }
    },
  },
})
```

- [ ] **Step 7: `admin/src/app/lang/index.ts`（M1 最小 i18n）**

```ts
import { createI18n } from 'vue-i18n'

const i18n = createI18n({
  legacy: false,
  locale: 'zh-cn',
  messages: {
    'zh-cn': {
      app: { title: 'ArkAdmin 方舟后台' },
      login: { title: '登录', username: '用户名', password: '密码', submit: '登 录' },
      common: { confirm: '确定', cancel: '取消', create: '新增', edit: '编辑', delete: '删除',
        search: '搜索', reset: '重置', success: '操作成功' },
    },
  },
})

export default i18n
```

`main.ts` 补 `app.use(i18n)`（import 自 `./app/lang`）。

- [ ] **Step 8: 验证 dev server 启动**

```bash
cd /home/gdmax/fastadmin/admin && npm run dev
```

Expected: `http://localhost:5175` 可访问（Task 12 阶段 App.vue 仍是脚手架默认页即可）

- [ ] **Step 9: Commit**

```bash
git add admin/ .gitignore
git commit -m "feat(m1): admin spa scaffold with axios envelope handling"
```

---

### Task 13: 登录页、动态路由与布局

**Files:**
- Create: `admin/src/app/router/index.ts`（重写占位）
- Create: `admin/src/app/utils/permission.ts`（重写占位）
- Create: `admin/src/app/views/login/index.vue`、`layout/index.vue`、`dashboard/index.vue`、`missing/index.vue`
- Modify: `admin/src/App.vue`（整体替换为 `<router-view />`）

**Interfaces:**
- Consumes: user store（Task 12）、`import.meta.glob('@/app/views/**/*.vue')`
- Produces: 动态路由机制——`mapMenusToRoutes(menus): RouteRecordRaw[]`（目录型菜单生成嵌套布局路由，叶子菜单按 `view_path` 定位组件，找不到组件渲染 `missing/index`）；全局前置守卫（无 token → `/login`；有 token 未加载 → `fetchMe()` + `addRoute`）；`v-permission` 指令（无权限移除元素）

- [ ] **Step 1: `admin/src/app/router/index.ts`**

```ts
import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'
import type { MenuItem } from '../api/auth'
import { useUserStore } from '../stores/user'

const appViews = import.meta.glob('@/app/views/**/*.vue')

export const Layout = () => import('@/app/views/layout/index.vue')

export function mapMenusToRoutes(menus: MenuItem[]): RouteRecordRaw[] {
  const routes: RouteRecordRaw[] = []
  for (const m of menus) {
    if (m.children?.length) {
      routes.push({
        path: m.route_path || `/${m.name}`,
        component: Layout,
        children: mapMenusToRoutes(m.children),
      })
    } else if (m.view_path) {
      const key = `/src/app/views/${m.view_path}.vue`
      routes.push({
        path: m.route_path || `/${m.name}`,
        name: m.name,
        component: (appViews as Record<string, () => Promise<unknown>>)[key]
          ?? (() => import('@/app/views/missing/index.vue')),
        meta: { title: m.title },
      })
    }
  }
  return routes
}

const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/login', name: 'login', component: () => import('@/app/views/login/index.vue') },
    { path: '/:pathMatch(.*)*', name: 'notfound', redirect: '/dashboard' },
  ],
})

router.beforeEach(async (to) => {
  const store = useUserStore()
  if (to.path === '/login') return true
  if (!store.token) return { path: '/login' }
  if (!store.routesAdded) {
    await store.fetchMe()
    for (const r of mapMenusToRoutes(store.menus)) {
      router.addRoute(r)
    }
    store.routesAdded = true
    return { ...to, replace: true }
  }
  return true
})

export default router
```

（插件视图的 glob（`@/addons/**/views/**/*.vue`）在 M3 接入，此处仅框架视图）

- [ ] **Step 2: `admin/src/app/utils/permission.ts`**

```ts
import type { Directive } from 'vue'
import { useUserStore } from '../stores/user'

export const permissionDirective: Directive<HTMLElement, string> = {
  mounted(el, binding) {
    const store = useUserStore()
    if (binding.value && !store.has(binding.value)) {
      el.parentNode?.removeChild(el)
    }
  },
}
```

- [ ] **Step 3: `admin/src/app/views/login/index.vue`**

```vue
<template>
  <div class="login-wrap">
    <el-card class="login-card">
      <h2>{{ t('app.title') }}</h2>
      <el-form :model="form" @keyup.enter="submit">
        <el-form-item>
          <el-input v-model="form.username" :placeholder="t('login.username')" size="large" />
        </el-form-item>
        <el-form-item>
          <el-input v-model="form.password" type="password" :placeholder="t('login.password')" size="large" show-password />
        </el-form-item>
        <el-button type="primary" size="large" style="width:100%" :loading="loading" @click="submit">
          {{ t('login.submit') }}
        </el-button>
      </el-form>
    </el-card>
  </div>
</template>

<script setup lang="ts">
import { reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useUserStore } from '../../stores/user'

const { t } = useI18n()
const router = useRouter()
const store = useUserStore()
const loading = ref(false)
const form = reactive({ username: '', password: '' })

async function submit() {
  if (!form.username || !form.password) return
  loading.value = true
  try {
    await store.login(form.username, form.password)
    await router.push('/dashboard')
  } finally {
    loading.value = false
  }
}
</script>

<style scoped>
.login-wrap { display: flex; align-items: center; justify-content: center; height: 100vh; background: #f0f2f5; }
.login-card { width: 380px; }
</style>
```

- [ ] **Step 4: `admin/src/app/views/layout/index.vue`（侧边栏递归菜单 + 头部）**

```vue
<template>
  <el-container class="layout">
    <el-aside width="220px">
      <div class="logo">{{ t('app.title') }}</div>
      <el-menu router :default-active="$route.path" class="menu">
        <menu-item v-for="m in store.menus" :key="m.id" :item="m" />
      </el-menu>
    </el-aside>
    <el-container>
      <el-header class="header">
        <span />
        <el-dropdown @command="onCommand">
          <span class="user">{{ store.adminInfo?.name || store.adminInfo?.username }}</span>
          <template #dropdown>
            <el-dropdown-menu>
              <el-dropdown-item command="logout">退出登录</el-dropdown-item>
            </el-dropdown-menu>
          </template>
        </el-dropdown>
      </el-header>
      <el-main><router-view /></el-main>
    </el-container>
  </el-container>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { useUserStore } from '../../stores/user'
import MenuItem from './components/MenuItem.vue'

const { t } = useI18n()
const router = useRouter()
const store = useUserStore()

function onCommand(cmd: string) {
  if (cmd === 'logout') {
    store.logout().then(() => router.push('/login'))
  }
}
</script>

<style scoped src="./layout.scss" lang="scss" />
```

`admin/src/app/views/layout/components/MenuItem.vue`：

```vue
<template>
  <el-sub-menu v-if="item.children?.length" :index="item.route_path || `/${item.name}`">
    <template #title>
      <el-icon v-if="item.icon"><component :is="iconComp" /></el-icon>
      <span>{{ item.title }}</span>
    </template>
    <menu-item v-for="c in item.children" :key="c.id" :item="c" />
  </el-sub-menu>
  <el-menu-item v-else :index="item.route_path || `/${item.name}`">
    <el-icon v-if="item.icon"><component :is="iconComp" /></el-icon>
    <span>{{ item.title }}</span>
  </el-menu-item>
</template>

<script setup lang="ts">
import { computed, type Component } from 'vue'
import * as Icons from '@element-plus/icons-vue'
import type { MenuItem } from '../../../api/auth'

const props = defineProps<{ item: MenuItem }>()
const iconComp = computed<Component | null>(() =>
  (Icons as Record<string, Component>)[props.item.icon] ?? null,
)
</script>
```

`admin/src/app/views/layout/layout.scss`：

```scss
.layout { height: 100vh; }
.logo { height: 56px; line-height: 56px; text-align: center; font-weight: 600; color: #fff; background: #001529; }
.menu { border-right: none; height: calc(100vh - 56px); overflow-y: auto; }
.header { display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid #e8e8e8; }
.user { cursor: pointer; }
```

（侧边栏深色样式由 el-menu 的 `background-color` 等属性微调，M1 从简）

- [ ] **Step 5: `dashboard/index.vue` 与 `missing/index.vue`**

```vue
<!-- admin/src/app/views/dashboard/index.vue -->
<template>
  <el-card>
    <h2>{{ store.adminInfo?.name }}，欢迎回来</h2>
    <p>ArkAdmin M1 · Laravel 12 + Vue 3 + Element Plus</p>
  </el-card>
</template>

<script setup lang="ts">
import { useUserStore } from '../../stores/user'
const store = useUserStore()
</script>
```

```vue
<!-- admin/src/app/views/missing/index.vue -->
<template>
  <el-result icon="warning" title="组件缺失"
    sub-title="该页面组件不存在，可能是插件已安装但后台未重新构建，请执行 npm run build 后重试" />
</template>
```

`admin/src/App.vue` 整体替换：

```vue
<template>
  <router-view />
</template>
```

删除脚手架自带的 `src/components/HelloWorld.vue` 与 `src/style.css` 引用（`main.ts` 无需引 style.css）。

- [ ] **Step 6: 手工验证登录与动态路由**

```bash
cd /home/gdmax/fastadmin/admin && npm run dev
```

浏览器 `http://localhost:5175`：未登录跳 `/login` → 用 `admin/123456` 登录 → 进入控制台，侧边栏出现 控制台/系统管理 及三个子项 → 点各子项页面正常（missing 提示页出现在 view_path 配错时）→ 右上角退出回登录页。

- [ ] **Step 7: Commit**

```bash
git add admin/src
git commit -m "feat(m1): login page, dynamic menu-driven routes and layout"
```

---

### Task 14: 系统管理页面与 M1 验收

**Files:**
- Create: `admin/src/app/api/admin.ts`、`role.ts`、`menu.ts`
- Create: `admin/src/app/views/system/admin/index.vue`、`role/index.vue`、`menu/index.vue`
- Modify: `AGENTS.md`（新增运行命令）

**Interfaces:**
- Consumes: Task 9/10/11 的全部 HTTP 接口；`v-permission` 指令
- Produces: 三个可用的管理页面（管理员：表格+搜索+新增/编辑对话框+删除；角色：表格+权限树勾选；菜单：树形表格+编辑对话框）；M1 验收全流程通过

- [ ] **Step 1: API 模块 `admin/src/app/api/admin.ts`**

```ts
import request from './request'
import type { MenuItem } from './auth'

export interface AdminRow { id: number; username: string; name: string; status: number; created_at?: string }

export const adminApi = {
  list: (params: Record<string, unknown>) => request.get<never, { list: AdminRow[]; total: number }>('/admins', { params }),
  store: (data: Record<string, unknown>) => request.post<never, unknown>('/admins', data),
  update: (id: number, data: Record<string, unknown>) => request.put<never, unknown>(`/admins/${id}`, data),
  destroy: (id: number) => request.delete<never, unknown>(`/admins/${id}`),
}

export interface RoleRow { id: number; name: string; permissions: string[] }

export const roleApi = {
  list: () => request.get<never, RoleRow[]>('/roles'),
  permissions: () => request.get<never, Record<string, { name: string; module: string }[]>>('/roles/permissions'),
  store: (data: { name: string; permissions: string[] }) => request.post<never, unknown>('/roles', data),
  update: (id: number, data: { name: string; permissions: string[] }) => request.put<never, unknown>(`/roles/${id}`, data),
  destroy: (id: number) => request.delete<never, unknown>(`/roles/${id}`),
}

export const menuApi = {
  list: () => request.get<never, MenuItem[]>('/menus'),
  store: (data: Partial<MenuItem>) => request.post<never, unknown>('/menus', data),
  update: (id: number, data: Partial<MenuItem>) => request.put<never, unknown>(`/menus/${id}`, data),
  destroy: (id: number) => request.delete<never, unknown>(`/menus/${id}`),
}
```

- [ ] **Step 2: `admin/src/app/views/system/admin/index.vue`**

```vue
<template>
  <el-card>
    <el-form inline>
      <el-form-item label="用户名">
        <el-input v-model="query.username" clearable @keyup.enter="load" />
      </el-form-item>
      <el-form-item>
        <el-button type="primary" @click="load">{{ t('common.search') }}</el-button>
      </el-form-item>
      <el-form-item>
        <el-button v-permission="'system.admin.store'" type="success" @click="openDialog()">
          {{ t('common.create') }}
        </el-button>
      </el-form-item>
    </el-form>

    <el-table :data="rows" border stripe>
      <el-table-column prop="id" label="ID" width="70" />
      <el-table-column prop="username" label="用户名" />
      <el-table-column prop="name" label="姓名" />
      <el-table-column label="状态" width="90">
        <template #default="{ row }">
          <el-tag :type="row.status === 1 ? 'success' : 'danger'">{{ row.status === 1 ? '启用' : '禁用' }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="操作" width="160">
        <template #default="{ row }">
          <el-button v-permission="'system.admin.update'" link type="primary" @click="openDialog(row)">
            {{ t('common.edit') }}
          </el-button>
          <el-button v-permission="'system.admin.destroy'" link type="danger" @click="remove(row)">
            {{ t('common.delete') }}
          </el-button>
        </template>
      </el-table-column>
    </el-table>
    <el-pagination class="pager" layout="total, prev, pager, next" :total="total"
      v-model:current-page="query.page" :page-size="15" @current-change="load" />

    <el-dialog v-model="dialog.visible" :title="dialog.form.id ? t('common.edit') : t('common.create')" width="480px">
      <el-form :model="dialog.form" label-width="80px">
        <el-form-item label="用户名"><el-input v-model="dialog.form.username" /></el-form-item>
        <el-form-item label="姓名"><el-input v-model="dialog.form.name" /></el-form-item>
        <el-form-item :label="dialog.form.id ? '新密码' : '密码'">
          <el-input v-model="dialog.form.password" type="password" show-password />
        </el-form-item>
        <el-form-item label="角色">
          <el-select v-model="dialog.form.roles" multiple style="width:100%">
            <el-option v-for="r in roles" :key="r.id" :label="r.name" :value="r.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="状态">
          <el-switch v-model="dialog.form.status" :active-value="1" :inactive-value="0" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialog.visible = false">{{ t('common.cancel') }}</el-button>
        <el-button type="primary" @click="save">{{ t('common.confirm') }}</el-button>
      </template>
    </el-dialog>
  </el-card>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ElMessage, ElMessageBox } from 'element-plus'
import { adminApi, roleApi, type AdminRow, type RoleRow } from '../../../api/admin'

const { t } = useI18n()
const rows = ref<AdminRow[]>([])
const roles = ref<RoleRow[]>([])
const total = ref(0)
const query = reactive({ username: '', page: 1 })

const dialog = reactive({
  visible: false,
  form: { id: 0, username: '', name: '', password: '', status: 1, roles: [] as number[] },
})

async function load() {
  const data = await adminApi.list(query)
  rows.value = data.list
  total.value = data.total
}

function openDialog(row?: AdminRow) {
  Object.assign(dialog, {
    visible: true,
    form: row
      ? { id: row.id, username: row.username, name: row.name, password: '', status: row.status, roles: [] }
      : { id: 0, username: '', name: '', password: '', status: 1, roles: [] },
  })
  // 编辑时回填角色：后端列表未含角色，简化为编辑时按需留空重选（M2 在 index 接口补 roles 字段）
}

async function save() {
  if (dialog.form.id) {
    await adminApi.update(dialog.form.id, { ...dialog.form })
  } else {
    await adminApi.store({ ...dialog.form })
  }
  ElMessage.success(t('common.success'))
  dialog.visible = false
  load()
}

async function remove(row: AdminRow) {
  await ElMessageBox.confirm(`确认删除管理员 ${row.username}？`, '提示', { type: 'warning' })
  await adminApi.destroy(row.id)
  ElMessage.success(t('common.success'))
  load()
}

onMounted(async () => {
  await load()
  roles.value = await roleApi.list()
})
</script>

<style scoped>
.pager { margin-top: 12px; justify-content: flex-end; }
</style>
```

（管理员列表的角色回填是已知简化项，M2 首个任务补 `roles` 字段——不阻塞 M1 验收）

- [ ] **Step 3: `admin/src/app/views/system/role/index.vue`**

```vue
<template>
  <el-card>
    <el-button v-permission="'system.role.store'" type="success" @click="openDialog()">
      {{ t('common.create') }}
    </el-button>
    <el-table :data="rows" border stripe style="margin-top:12px">
      <el-table-column prop="id" label="ID" width="70" />
      <el-table-column prop="name" label="角色标识" />
      <el-table-column label="权限数" width="90">
        <template #default="{ row }">{{ row.permissions.length }}</template>
      </el-table-column>
      <el-table-column label="操作" width="160">
        <template #default="{ row }">
          <el-button v-permission="'system.role.update'" link type="primary" :disabled="row.name === 'super_admin'"
            @click="openDialog(row)">{{ t('common.edit') }}</el-button>
          <el-button v-permission="'system.role.destroy'" link type="danger" :disabled="row.name === 'super_admin'"
            @click="remove(row)">{{ t('common.delete') }}</el-button>
        </template>
      </el-table-column>
    </el-table>

    <el-dialog v-model="dialog.visible" :title="dialog.form.id ? t('common.edit') : t('common.create')" width="560px">
      <el-form :model="dialog.form" label-width="80px">
        <el-form-item label="角色标识"><el-input v-model="dialog.form.name" /></el-form-item>
        <el-form-item label="权限">
          <el-tree ref="treeRef" :data="permTree" show-checkbox node-key="name"
            :props="{ label: 'name', children: 'children' }" default-expand-all
            :default-checked-keys="dialog.form.permissions" style="width:100%" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialog.visible = false">{{ t('common.cancel') }}</el-button>
        <el-button type="primary" @click="save">{{ t('common.confirm') }}</el-button>
      </template>
    </el-dialog>
  </el-card>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ElMessage, ElMessageBox } from 'element-plus'
import { roleApi, type RoleRow } from '../../../api/admin'

const { t } = useI18n()
const rows = ref<RoleRow[]>([])
const grouped = ref<Record<string, { name: string; module: string }[]>>({})
const treeRef = ref()
const dialog = reactive({ visible: false, form: { id: 0, name: '', permissions: [] as string[] } })

const permTree = computed(() =>
  Object.entries(grouped.value).map(([module, items]) => ({
    name: module,
    children: items.map((i) => ({ name: i.name, module: i.module })),
  })))

async function load() {
  rows.value = await roleApi.list()
}

function openDialog(row?: RoleRow) {
  Object.assign(dialog, {
    visible: true,
    form: row ? { id: row.id, name: row.name, permissions: [...row.permissions] } : { id: 0, name: '', permissions: [] },
  })
}

async function save() {
  const checked = treeRef.value?.getCheckedKeys(true) ?? []
  if (dialog.form.id) {
    await roleApi.update(dialog.form.id, { name: dialog.form.name, permissions: checked })
  } else {
    await roleApi.store({ name: dialog.form.name, permissions: checked })
  }
  ElMessage.success(t('common.success'))
  dialog.visible = false
  load()
}

async function remove(row: RoleRow) {
  await ElMessageBox.confirm(`确认删除角色 ${row.name}？`, '提示', { type: 'warning' })
  await roleApi.destroy(row.id)
  ElMessage.success(t('common.success'))
  load()
}

onMounted(async () => {
  await load()
  grouped.value = await roleApi.permissions()
})
</script>
```

- [ ] **Step 4: `admin/src/app/views/system/menu/index.vue`**

```vue
<template>
  <el-card>
    <el-button v-permission="'system.menu.store'" type="success" @click="openDialog()">
      {{ t('common.create') }}
    </el-button>
    <el-table :data="rows" row-key="id" border default-expand-all style="margin-top:12px">
      <el-table-column prop="title" label="标题" min-width="140" />
      <el-table-column prop="name" label="标识" min-width="120" />
      <el-table-column prop="route_path" label="路由" min-width="120" />
      <el-table-column prop="view_path" label="组件" min-width="140" />
      <el-table-column prop="permission" label="权限串" min-width="140" />
      <el-table-column label="显示" width="70">
        <template #default="{ row }">
          <el-tag :type="row.is_show ? 'success' : 'info'">{{ row.is_show ? '是' : '否' }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="操作" width="160">
        <template #default="{ row }">
          <el-button v-permission="'system.menu.update'" link type="primary" @click="openDialog(row)">
            {{ t('common.edit') }}
          </el-button>
          <el-button v-permission="'system.menu.destroy'" link type="danger" @click="remove(row)">
            {{ t('common.delete') }}
          </el-button>
        </template>
      </el-table-column>
    </el-table>

    <el-dialog v-model="dialog.visible" :title="dialog.form.id ? t('common.edit') : t('common.create')" width="560px">
      <el-form :model="dialog.form" label-width="90px">
        <el-form-item label="父级菜单">
          <el-tree-select v-model="dialog.form.parent_id" :data="parentOptions" check-strictly
            :props="{ label: 'title', value: 'id' }" node-key="id" style="width:100%" />
        </el-form-item>
        <el-form-item label="标题"><el-input v-model="dialog.form.title" /></el-form-item>
        <el-form-item label="标识"><el-input v-model="dialog.form.name" placeholder="如 system.admin" /></el-form-item>
        <el-form-item label="图标"><el-input v-model="dialog.form.icon" placeholder="Element Plus 图标名" /></el-form-item>
        <el-form-item label="路由路径"><el-input v-model="dialog.form.route_path" placeholder="/system/admin" /></el-form-item>
        <el-form-item label="组件路径"><el-input v-model="dialog.form.view_path" placeholder="目录型留空" /></el-form-item>
        <el-form-item label="权限串"><el-input v-model="dialog.form.permission" /></el-form-item>
        <el-form-item label="排序"><el-input-number v-model="dialog.form.sort" /></el-form-item>
        <el-form-item label="是否显示"><el-switch v-model="dialog.form.is_show" /></el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialog.visible = false">{{ t('common.cancel') }}</el-button>
        <el-button type="primary" @click="save">{{ t('common.confirm') }}</el-button>
      </template>
    </el-dialog>
  </el-card>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ElMessage, ElMessageBox } from 'element-plus'
import { menuApi } from '../../../api/admin'
import type { MenuItem } from '../../../api/auth'

const { t } = useI18n()
const rows = ref<MenuItem[]>([])
const dialog = reactive({
  visible: false,
  form: {} as Partial<MenuItem> & { parent_id: number },
})

const parentOptions = ref<MenuItem[]>([])

async function load() {
  rows.value = await menuApi.list()
  parentOptions.value = [{ id: 0, parent_id: 0, name: 'root', title: '顶级', icon: '', route_path: '',
    view_path: '', permission: '', addon_key: '', sort: 0, children: rows.value }]
}

function openDialog(row?: MenuItem) {
  Object.assign(dialog, {
    visible: true,
    form: row ? { ...row } : { parent_id: 0, sort: 0, is_show: true } as never,
  })
}

async function save() {
  if (dialog.form.id) {
    await menuApi.update(dialog.form.id, dialog.form)
  } else {
    await menuApi.store(dialog.form)
  }
  ElMessage.success(t('common.success'))
  dialog.visible = false
  load()
}

async function remove(row: MenuItem) {
  await ElMessageBox.confirm(`确认删除菜单 ${row.title}（含子菜单）？`, '提示', { type: 'warning' })
  await menuApi.destroy(row.id)
  ElMessage.success(t('common.success'))
  load()
}

onMounted(load)
</script>
```

- [ ] **Step 5: M1 端到端验收（设计文档 §10 M1 完成标志）**

后端全量测试：

```bash
docker compose -f docker/docker-compose.yml exec php sh -c "cd /var/www/server && php artisan test"
```

Expected: 全部通过

手工验收（`http://localhost:5175`）：

1. `admin/123456` 登录成功，进入控制台
2. 系统管理 → 管理员管理：新增管理员 `op01/123456`（不分配角色）
3. 角色管理：新建角色 `demo`，勾选 `system.admin.index` 权限；编辑 `op01` 分配角色 `demo`
4. 退出，用 `op01/123456` 登录：侧边栏只见 控制台 + 系统管理→管理员管理；直接访问 `/system/role` 被重定向；`curl` 验证接口拒绝：
   `curl -s -X GET http://localhost:8080/api/admin/admins -H "Authorization: Bearer <op01token>"` 返回 403 信封
5. op01 的新增按钮不显示（v-permission 生效）

- [ ] **Step 6: 更新 `AGENTS.md` 工作区定位段（结构图与启动命令）**

在「工作区定位」一节的结构图中补上 `server/`、`admin/`、`docker/`，并新增段落：

```markdown
## 常用命令

- 后端起停：`docker compose -f docker/docker-compose.yml up -d`
- 后端测试：`docker compose -f docker/docker-compose.yml exec php sh -c "cd /var/www/server && php artisan test"`
- 前端开发：`cd admin && npm run dev`（http://localhost:5175，代理 /api → 8080）
- 超管账号：admin / 123456（开发环境种子数据）
```

- [ ] **Step 7: 最终提交**

```bash
git add admin/src AGENTS.md
git commit -m "feat(m1): system management pages, m1 acceptance passed"
git log --oneline
```

---

## Self-Review 记录

- **Spec 覆盖**：M1 行（docker 环境→T2、Laravel 骨架→T3、登录/Token→T5/T8、管理员/角色/权限 CRUD→T9/T10、menus 表与菜单接口→T7/T8/T11、admin SPA 登录+动态路由+核心页面→T12/T13/T14）；§5.2 信封→T4；§5.3 guard 与权限串→T5/T6；§7.2 动态路由与 missing 兜底→T13。用户要求的「参考仓库不进 git」→T1。
- **占位符扫描**：T12 Step 3 的 router/指令占位属任务间接力（T13 重写为真实现），已显式标注；管理员列表角色回填简化项已标注 M2 补齐。无其他 TBD。
- **类型一致性**：`MenuItem`（T12 定义，T13/T14 复用）；`success/fail/paginate`（T4 定义，T8-T11 使用）；`authApi/adminApi/roleApi/menuApi`（T12/T14 与页面使用一致）；`system.<m>.<action>` 权限串全文一致。
