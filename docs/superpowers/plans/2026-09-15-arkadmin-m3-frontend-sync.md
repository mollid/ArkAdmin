# ArkAdmin M3 前端同步 — 实施计划

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 实现设计文档 §6.6/§7.2/§7.3 前端同步——`addon:install` 把插件前端复制进 `admin/src/addons/<key>/`、卸载删除、前端按 `addon_key + view_path` 动态解析插件组件并合并插件语言包，M3 完成标志：demo 插件带真实 Vue 页面在后台跑通。

**Architecture:** 后端 `AddonInstaller` 增加 `syncFrontend/purgeFrontend`（admin 根目录经 `config('arkadmin.admin_path')` 注入，测试可指向临时目录隔离文件系统副作用）；前端 `router/index.ts` 抽出 `resolveView(menu, appViews?, addonViews?)` 纯函数（插件菜单查 `/src/addons/<key>/views/<view_path>.vue`，miss 落到既有 missing 兜底页），`lang/index.ts` 以 eager glob 按插件 key 命名空间合并 `lang/zh-cn.ts`。插件前端源码唯一来源是 `addons/<key>/admin/`，复制产物 `admin/src/addons/` 进 .gitignore。

**Tech Stack:** 既有 Laravel 13/Pest 4 后端；前端 Vue3 + TS + Vite 8 + vue-i18n；**新增 Vitest**（设计文档 §3 前端测试选型，首个落地）。

**Spec:** `docs/superpowers/specs/2026-09-14-arkadmin-design.md` §6.6（前端复制/删除与构建提示）、§7.2（动态路由插件 glob 与 404 兜底）、§7.3（i18n 命名空间合并）、§10 M3 行（完成标志：M2 空插件带上一个 Vue 页面跑通）。

## Global Constraints

- 插件前端目录约定（§6.1）：`addons/<key>/admin/{views,api,lang}`，安装时复制到 `admin/src/addons/<key>/`
- 插件菜单 `view_path` 相对插件前端根（如 `note/index` → `admin/src/addons/<key>/views/note/index.vue`）
- i18n 键形如 `demo.note.title`（§7.3：按插件 key 命名空间合并，不与框架包冲突）
- `admin/src/addons/` 是生成产物：源码唯一来源 `addons/<key>/admin/`，进 `.gitignore`
- 装插件需重构建 admin是已接受代价（§1 决策 2）：CLI 复制发生时输出构建提示；开发环境 Vite dev 直接生效
- 后端测试在 docker 内跑（同 M2 命令）；前端测试 `cd admin && npm test`
- NiuShop 只学机制零复制代码；FastAdmin（Apache-2.0）可借鉴
- 权限/信封/表前缀等 M1/M2 约定不变

## File Structure

```
server/
├── config/arkadmin.php                          # 改：+ admin_path
├── app/Support/Addon/AddonInstaller.php         # 改：+ syncFrontend/purgeFrontend，install/uninstall 挂钩
├── app/Support/Addon/Console/AddonInstallCommand.php  # 改：复制发生时输出构建提示
└── tests/
    ├── Pest.php                                 # 改：全局 afterEach 清理前端复制产物
    └── Feature/Addon/AddonFrontendSyncTest.php  # 新
admin/
├── .gitignore（根 .gitignore 追加）              # 改：admin/src/addons/
├── package.json                                 # 改：+ vitest devDep + test script
├── src/app/
│   ├── router/index.ts                          # 改：抽 resolveView + 插件 glob
│   ├── lang/index.ts                            # 改：合并插件语言包
│   └── api/auth.ts                              # 不动（MenuItem 已含 addon_key）
└── tests/router.test.ts                         # 新：resolveView 单测
addons/demo/admin/
├── views/note/index.vue                         # 新：便签页（列表+新增）
├── api/note.ts                                  # 新：接口封装
└── lang/zh-cn.ts                                # 新：插件语言包
```

---

### Task 1: 后端前端同步 — syncFrontend / purgeFrontend 与构建提示

**Files:**
- Modify: `server/config/arkadmin.php`
- Modify: `server/app/Support/Addon/AddonInstaller.php`
- Modify: `server/app/Support/Addon/Console/AddonInstallCommand.php`
- Modify: `server/tests/Pest.php`（全局 afterEach）
- Modify: 仓库根 `.gitignore`
- Test: `server/tests/Feature/Addon/AddonFrontendSyncTest.php`

**Interfaces:**
- Consumes: `AddonInfo::$dir`、`AddonManager::addonPath()`（M2）
- Produces:
  - `config('arkadmin.admin_path')`（string，默认 `dirname(base_path()).'/admin'`）
  - `AddonInstaller::frontendDir(string $addonName): string`（public，`<admin_path>/src/addons/<name>`，测试用）
  - install() 行为新增：末尾（finish 之后）调用 `syncFrontend($info)`——`addons/<key>/admin/` 存在则递归复制到 `<admin_path>/src/addons/<key>/`（先清后拷，可重入），不存在则跳过
  - uninstall() 行为新增：删除 `<admin_path>/src/addons/<key>/`
  - CLI：`addon:install` 复制发生后输出 `前端已同步至 admin/src/addons/<name>，生产环境请执行 cd admin && npm run build 完成后台构建`

- [ ] **Step 1: 写失败测试** — `server/tests/Feature/Addon/AddonFrontendSyncTest.php`

```php
<?php

use App\Support\Addon\AddonInstaller;
use App\Support\Addon\AddonManager;

beforeEach(function () {
    (new App\Admin\Seeds\RbacSeeder)->run();
    // 前端产物隔离到临时目录，避免测试污染真实 admin/src/addons
    config(['arkadmin.admin_path' => storage_path('framework/admin-fixture')]);
});

afterEach(function () {
    remove_dir(storage_path('framework/admin-fixture'));
});

/** 生成带前端目录的 fixture 插件：admin/views/x/index.vue + admin/lang/zh-cn.ts */
function make_frontend_addon(string $name): string
{
    $dir = make_addon_dir($name);
    foreach ([
        '/admin/views/x/index.vue' => '<template><div>x</div></template>',
        '/admin/lang/zh-cn.ts' => "export default { x: { title: 'X' } }\n",
    ] as $file => $content) {
        $path = $dir.$file;
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $content);
    }

    return $dir;
}

it('install 把插件 admin/ 复制到 admin/src/addons/<key>', function () {
    config(['arkadmin.addon_path' => storage_path('framework/addon-fixture')]);
    make_frontend_addon('fe');

    app(AddonInstaller::class)->install('fe');

    $frontend = config('arkadmin.admin_path').'/src/addons/fe';
    expect(is_file($frontend.'/views/x/index.vue'))->toBeTrue()
        ->and(is_file($frontend.'/lang/zh-cn.ts'))->toBeTrue()
        // 只复制 admin/ 子树，插件后端源码不得泄漏进前端目录
        ->and(is_dir($frontend.'/src'))->toBeFalse()
        ->and(is_file($frontend.'/info.json'))->toBeFalse();
});

it('install 提示构建命令（仅当插件带前端）', function () {
    config(['arkadmin.addon_path' => storage_path('framework/addon-fixture')]);
    make_frontend_addon('fe');

    $this->artisan('addon:install', ['name' => 'fe'])
        ->expectsOutputToContain('npm run build')
        ->assertExitCode(0);

    // 无 admin/ 目录的插件不提示（不误导纯后端插件使用者）
    make_addon_dir('beonly');
    $this->artisan('addon:install', ['name' => 'beonly'])
        ->doesntExpectOutputToContain('npm run build')
        ->assertExitCode(0);
});

it('重复 install 覆盖而非叠加上次残留', function () {
    config(['arkadmin.addon_path' => storage_path('framework/addon-fixture')]);
    make_frontend_addon('fe');
    $installer = app(AddonInstaller::class);
    $installer->install('fe');

    // 上次复制产物中塞一个脏文件，重装后应消失（先清后拷）
    $stale = config('arkadmin.admin_path').'/src/addons/fe/views/stale.vue';
    file_put_contents($stale, 'stale');
    $installer->disable('fe');
    $installer->uninstall('fe');
    $installer->install('fe');
    expect(is_file($stale))->toBeFalse()
        ->and(is_file(config('arkadmin.admin_path').'/src/addons/fe/views/x/index.vue'))->toBeTrue();
});

it('uninstall 删除前端产物', function () {
    config(['arkadmin.addon_path' => storage_path('framework/addon-fixture')]);
    make_frontend_addon('fe');
    $installer = app(AddonInstaller::class);
    $installer->install('fe');
    $installer->disable('fe');
    $installer->uninstall('fe');

    expect(is_dir(config('arkadmin.admin_path').'/src/addons/fe'))->toBeFalse();
});

it('keep-data 卸载同样删除前端产物', function () {
    config(['arkadmin.addon_path' => storage_path('framework/addon-fixture')]);
    make_frontend_addon('fe');
    $installer = app(AddonInstaller::class);
    $installer->install('fe');
    $installer->disable('fe');
    $installer->uninstall('fe', keepData: true);

    expect(is_dir(config('arkadmin.admin_path').'/src/addons/fe'))->toBeFalse();
});
```

- [ ] **Step 2: 跑测试确认失败**

Run: `docker compose -f docker/docker-compose.yml exec -u 1000:1000 php sh -c "cd /var/www/server && php artisan test --filter=AddonFrontendSyncTest"`
Expected: FAIL（复制行为不存在，文件不存在断言全挂）

- [ ] **Step 3: 实现**

`server/config/arkadmin.php` 追加：

```php
    // admin 前端根目录（复制插件前端 admin/ → <此目录>/src/addons/<key>/）
    'admin_path' => env('ARKADMIN_ADMIN_PATH', dirname(base_path()).'/admin'),
```

`AddonInstaller` 追加（放在 `finish()` 之后）：

```php
    /** 插件前端产物目录：<admin_path>/src/addons/<name> */
    public function frontendDir(string $addonName): string
    {
        return rtrim((string) config('arkadmin.admin_path'), '/').'/src/addons/'.$addonName;
    }

    /** §6.6：addons/<key>/admin/ → admin/src/addons/<key>/，先清后拷保证可重入；无前端则跳过 */
    protected function syncFrontend(AddonInfo $info): bool
    {
        $src = $info->dir.'/admin';
        if (! is_dir($src)) {
            return false;
        }
        $this->purgeFrontend($info->name);
        $dest = $this->frontendDir($info->name);
        @mkdir($dest, 0777, true);
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($items as $item) {
            $target = $dest.'/'.$items->getSubPathName();
            $item->isDir() ? @mkdir($target, 0777, true) : @copy($item->getPathname(), $target);
        }

        return true;
    }

    protected function purgeFrontend(string $addonName): void
    {
        $dir = $this->frontendDir($addonName);
        if (is_dir($dir)) {
            remove_dir($dir);
        }
    }
```

（`remove_dir` 在 `tests/Pest.php` 定义、仅测试进程可用——生产不可用！改为内联私有实现：）

```php
    protected function purgeFrontend(string $addonName): void
    {
        $dir = $this->frontendDir($addonName);
        if (! is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
```

install() 末尾（`$this->finish($info);` 之后、`return $record;` 之前）：

```php
        $this->syncFrontend($info);
```

uninstall() 中 `$record->delete();` 之后追加：

```php
        $this->purgeFrontend($name);
```

`AddonInstallCommand::handle` 改为（安装成功消息之后）：

```php
        $frontendDir = $installer->frontendDir($name);
        if (is_dir($installer->manager?->addonPath().'/'.$name.'/admin')) {
            $this->info("前端已同步至 admin/src/addons/{$name}，生产环境请执行 cd admin && npm run build 完成后台构建");
        }
```

——不可访问 protected `$manager`。改为直接判断插件源目录（与 installer 内 syncFrontend 的判定一致）：

```php
        $addonAdminDir = rtrim((string) config('arkadmin.addon_path'), '/')."/{$name}/admin";
        if (is_dir($addonAdminDir)) {
            $this->info("前端已同步至 admin/src/addons/{$name}，生产环境请执行 cd admin && npm run build 完成后台构建");
        }
```

`server/tests/Pest.php` 末尾追加全局清理（文件系统不随 RefreshDatabase 回滚；admin/src/addons 是生成产物，测试结束清空安全）：

```php
// 插件前端复制产物不随 DB 事务回滚；套件级清空（目录是生成物，源码在 addons/<key>/admin）
afterEach(function () {
    remove_dir(rtrim((string) config('arkadmin.admin_path'), '/').'/src/addons');
});
```

⚠️ 该 afterEach 与各测试文件自己的 afterEach 共存，Pest 全局 afterEach 先于文件级执行——文件级 `remove_dir(storage_path('framework/admin-fixture'))` 照常。注意全局 afterEach 在**所有**测试（含 M1 的 AuthTest 等）后运行；`config('arkadmin.admin_path')` 默认真实 `/var/www/admin`，`remove_dir('/var/www/admin/src/addons')` 对不存在目录是 no-op，安全。

仓库根 `.gitignore` 在"依赖与产物"段追加：

```
# 插件前端复制产物（源码在 addons/<key>/admin，安装时生成）
admin/src/addons/
```

- [ ] **Step 4: 跑测试确认通过**

Run: `docker compose -f docker/docker-compose.yml exec -u 1000:1000 php sh -c "cd /var/www/server && php artisan test --filter=AddonFrontendSyncTest"`
Expected: PASS（5 项）

- [ ] **Step 5: 全量回归 + 提交**

```bash
docker compose -f docker/docker-compose.yml exec -u 1000:1000 php sh -c "cd /var/www/server && php artisan test"
git add server .gitignore
git commit -m "feat(m3): sync addon frontend on install/uninstall with rebuild hint"
```

---

### Task 2: 前端路由解析 — resolveView 插件映射 + Vitest

**Files:**
- Modify: `admin/src/app/router/index.ts`
- Modify: `admin/package.json`（+ vitest + test script）
- Test: `admin/tests/router.test.ts`（新建 `admin/tests/` 目录）

**Interfaces:**
- Consumes: `MenuItem`（`admin/src/app/api/auth.ts`，已含 `addon_key: string`）
- Produces:
  - `resolveView(menu: MenuItem, appViews?: ViewMap, addonViews?: ViewMap): () => Promise<unknown>`，`ViewMap = Record<string, () => Promise<unknown>>`
  - 解析规则：`addon_key` 非空 → key `/src/addons/${addon_key}/views/${view_path}.vue`（addonViews 中查）；空 → key `/src/app/views/${view_path}.vue`（appViews 中查）；两处皆 miss → 共享 `missingView` loader
  - `mapMenusToRoutes` 内部改用 `resolveView(m)`（默认参数取真实 glob）
  - `npm test` → `vitest run`

- [ ] **Step 1: 安装 vitest**

```bash
cd admin && npm install -D vitest --registry=https://mirrors.cloud.tencent.com/npm/
```

`package.json` scripts 追加：`"test": "vitest run"`。（vitest 复用仓库 vite 8 管道与 vue 插件，无需独立配置文件。）

- [ ] **Step 2: 写失败测试** — `admin/tests/router.test.ts`

```ts
import { describe, expect, it } from 'vitest'
import { missingView, resolveView } from '../src/app/router'
import type { MenuItem } from '../src/app/api/auth'

const menu = (over: Partial<MenuItem>): MenuItem => ({
  id: 1, parent_id: 0, name: 'm', title: 'M', icon: '', route_path: '', view_path: '',
  permission: '', addon_key: '', sort: 0, children: [], ...over,
})

const appViews = { '/src/app/views/dashboard/index.vue': () => import('../src/app/views/dashboard/index.vue') }
const addonViews = { '/src/addons/demo/views/note/index.vue': () => import('../src/app/views/missing/index.vue') }

describe('resolveView', () => {
  it('框架菜单（addon_key 空）映射 app views', () => {
    const loader = resolveView(menu({ view_path: 'dashboard/index' }), appViews, {})
    expect(loader).toBe(appViews['/src/app/views/dashboard/index.vue'])
  })

  it('插件菜单映射 addons/<key>/views/<view_path>', () => {
    const loader = resolveView(
      menu({ addon_key: 'demo', view_path: 'note/index' }), appViews, addonViews,
    )
    expect(loader).toBe(addonViews['/src/addons/demo/views/note/index.vue'])
  })

  it('插件组件缺失落到 missing 兜底（未重构建场景）', () => {
    const loader = resolveView(
      menu({ addon_key: 'demo', view_path: 'ghost/index' }), appViews, addonViews,
    )
    expect(loader).toBe(missingView)
  })

  it('框架组件缺失同样落到 missing', () => {
    const loader = resolveView(menu({ view_path: 'ghost/index' }), appViews, addonViews)
    expect(loader).toBe(missingView)
  })
})
```

（注：测试里 addonViews 的 value 指向任意存在的 .vue 模块即可——断言的是引用相等，不真正加载组件。）

- [ ] **Step 3: 跑测试确认失败**

Run: `cd admin && npm test`
Expected: FAIL（`missingView`/`resolveView` 未导出）

- [ ] **Step 4: 实现 — 改 `admin/src/app/router/index.ts`**

顶部 glob 区改为：

```ts
const appViews = import.meta.glob('/src/app/views/**/*.vue')
const addonViews = import.meta.glob('/src/addons/**/views/**/*.vue')

// 共享缺失兜底 loader：miss 断言与运行时行为一致（§7.2 组件缺失提示页）
export const missingView = () => import('@/app/views/missing/index.vue')

export type ViewMap = Record<string, () => Promise<unknown>>

/**
 * 菜单 view_path → 组件 loader。addon_key 非空查插件目录（§7.2）：
 * /src/addons/<key>/views/<view_path>.vue；框架菜单查 /src/app/views/。miss → missingView。
 * glob 以默认参数注入，测试可传 fake map 而不落文件系统。
 */
export function resolveView(
  m: MenuItem,
  appV: ViewMap = appViews as ViewMap,
  addonV: ViewMap = addonViews as ViewMap,
): () => Promise<unknown> {
  const key = m.addon_key
    ? `/src/addons/${m.addon_key}/views/${m.view_path}.vue`
    : `/src/app/views/${m.view_path}.vue`
  const map = m.addon_key ? addonV : appV
  return map[key] ?? missingView
}
```

`mapMenusToRoutes` 中原 `const key = ... const view = ...` 两行替换为：

```ts
      const view = resolveView(m)
```

- [ ] **Step 5: 跑测试确认通过 + 类型检查**

```bash
cd admin && npm test && npx vue-tsc -b
```
Expected: 4 项 PASS；vue-tsc 无错误

- [ ] **Step 6: 全量后端回归（确认 router 改动无服务端影响）+ 提交**

```bash
docker compose -f docker/docker-compose.yml exec -u 1000:1000 php sh -c "cd /var/www/server && php artisan test"
git add admin/package.json admin/package-lock.json admin/src admin/tests
git commit -m "feat(m3): resolve addon view components with missing fallback, add vitest"
```

---

### Task 3: demo 插件前端页面与 i18n 合并

**Files:**
- Create: `addons/demo/admin/views/note/index.vue`
- Create: `addons/demo/admin/api/note.ts`
- Create: `addons/demo/admin/lang/zh-cn.ts`
- Modify: `admin/src/app/lang/index.ts`

**Interfaces:**
- Consumes: `resolveView` 的插件 key 约定（T2）、demo 后端接口 `GET/POST /api/admin/addon/demo/notes`（M2）、权限串 `addon.demo.note.index/store`
- Produces: 插件前端三件套标准结构（views/api/lang）；`lang/zh-cn.ts` 契约——`export default { ... }` 对象整体并入 `messages['zh-cn'][<插件key>]`（键形如 `demo.note.title`）；页面经 `useI18n` 直接 `t('demo.note.xxx')`

- [ ] **Step 1: demo 前端三件套**

`addons/demo/admin/api/note.ts`：

```ts
import request from '@/app/api/request'

export interface Note {
  id: number
  admin_id: number
  content: string
  created_at: string
  updated_at: string
}

export const noteApi = {
  list: () => request.get<never, { list: Note[] }>('/addon/demo/notes'),
  store: (data: { content: string }) => request.post<never, { id: number }>('/addon/demo/notes', data),
}
```

`addons/demo/admin/lang/zh-cn.ts`：

```ts
/* 插件语言包：整体并入框架 i18n 的 zh-cn.<插件key> 命名空间（§7.3） */
export default {
  note: {
    title: '便签',
    content: '内容',
    contentRequired: '请输入便签内容',
    create: '写一条',
    empty: '还没有便签，写一条吧',
    createdBy: '管理员',
  },
}
```

`addons/demo/admin/views/note/index.vue`：

```vue
<template>
  <el-card>
    <template #header>
      <div class="card-header">
        <span>{{ t('demo.note.title') }}</span>
        <el-button v-if="user.has('addon.demo.note.store')" type="primary" @click="dialog = true">
          {{ t('demo.note.create') }}
        </el-button>
      </div>
    </template>

    <el-empty v-if="!rows.length" :description="t('demo.note.empty')" />
    <el-timeline v-else>
      <el-timeline-item v-for="n in rows" :key="n.id" :timestamp="n.created_at">
        {{ n.content }}
        <span class="meta">#{{ n.id }} · {{ t('demo.note.createdBy') }}#{{ n.admin_id }}</span>
      </el-timeline-item>
    </el-timeline>

    <el-dialog v-model="dialog" :title="t('demo.note.create')" width="420px">
      <el-input v-model="content" type="textarea" :rows="3" :placeholder="t('demo.note.contentRequired')" />
      <template #footer>
        <el-button @click="dialog = false">{{ t('common.cancel') }}</el-button>
        <el-button type="primary" :disabled="!content.trim()" @click="save">{{ t('common.confirm') }}</el-button>
      </template>
    </el-dialog>
  </el-card>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ElMessage } from 'element-plus'
import { noteApi, type Note } from '../../api/note'
import { useUserStore } from '@/app/stores/user'

const { t } = useI18n()
const user = useUserStore()
const rows = ref<Note[]>([])
const dialog = ref(false)
const content = ref('')

async function load() {
  rows.value = (await noteApi.list()).list
}

async function save() {
  await noteApi.store({ content: content.value.trim() })
  ElMessage.success(t('common.success'))
  dialog.value = false
  content.value = ''
  load()
}

onMounted(load)
</script>

<style scoped>
.card-header { display: flex; align-items: center; justify-content: space-between; }
.meta { color: var(--el-text-color-secondary); font-size: 12px; margin-left: 8px; }
</style>
```

- [ ] **Step 2: 框架 i18n 合并插件语言包 — 改 `admin/src/app/lang/index.ts`**

```ts
import { createI18n } from 'vue-i18n'

const messages: Record<string, Record<string, unknown>> = {
  'zh-cn': {
    app: { title: 'ArkAdmin 方舟后台' },
    login: { title: '登录', username: '用户名', password: '密码', submit: '登 录',
      usernameRequired: '请输入用户名', passwordRequired: '请输入密码' },
    common: { confirm: '确定', cancel: '取消', create: '新增', edit: '编辑', delete: '删除',
      search: '搜索', reset: '重置', success: '操作成功' },
  },
}

// §7.3 插件语言包：admin/src/addons/<key>/lang/zh-cn.ts 整体并入 zh-cn.<key> 命名空间。
// eager 静态合入（构建期定死，无运行时开销）；未安装任何插件时 glob 为空。
const addonLangs = import.meta.glob('/src/addons/*/lang/zh-cn.ts', { eager: true }) as Record<
  string,
  { default: Record<string, unknown> }
>
for (const [path, mod] of Object.entries(addonLangs)) {
  const key = path.split('/')[3] // /src/addons/<key>/lang/zh-cn.ts
  messages['zh-cn'][key] = mod.default
}

const i18n = createI18n({
  legacy: false,
  locale: 'zh-cn',
  messages,
})

export default i18n
```

- [ ] **Step 3: 复制 + 构建/测试验证**

```bash
D="docker compose -f docker/docker-compose.yml exec -T -u 1000:1000 php sh -c"
$D "cd /var/www/server && php artisan addon:install demo"   # 复制 demo 前端到 admin/src/addons/demo
cd admin && npx vue-tsc -b && npm run build && npm test
```
Expected: 类型检查过、构建成功（demo 页面入产物）、router 单测 4 项过；`admin/dist` 中存在 `addons/demo` 相关 chunk（`ls dist/assets | grep -i demo` 或构建输出可见）。构建后 uninstall 恢复干净（dev 环境由 T4 处理）。

- [ ] **Step 4: 后端全量回归 + 提交**

```bash
docker compose -f docker/docker-compose.yml exec -u 1000:1000 php sh -c "cd /var/www/server && php artisan test"
git add addons/demo/admin admin/src/app/lang/index.ts
git commit -m "feat(m3): demo addon vue page, api and i18n namespaced merge"
```

（注意：`admin/src/addons/demo` 是复制产物已 gitignore，不会误提交；提交前 `git status` 确认。）

---

### Task 4: 端到端验收（GUI）与收尾

**Files:**
- Modify: `AGENTS.md`（工作区定位补 admin/src/addons 产物说明、常用命令补 npm test）
- Create: `docs/superpowers/plans/2026-09-15-arkadmin-m3-wrapup.md`

- [ ] **Step 1: 开发库安装 demo + 起 dev server**

```bash
D="docker compose -f docker/docker-compose.yml exec -T -u 1000:1000 php sh -c"
$D "cd /var/www/server && php artisan addon:install demo"
cd admin && npm run dev   # 后台运行，http://localhost:5175
```

- [ ] **Step 2: Playwright GUI 走查（M3 完成标志）**

1. `admin/123456` 登录 → 侧边栏出现"演示插件 → 便签"（M2 时点击是 missing 页，现在必须是真实便签页）
2. 便签页渲染 `demo.note.*` 文案（验证 i18n 合并）；列表含历史 `login:admin` 记录（M2 事件监听写入）
3. 点"写一条" → 输入内容 → 确定 → 时间线出现新便签（真实接口闭环）
4. 无 `addon.demo.note.store` 权限的账号看不到"写一条"按钮（v-permission/has 生效）：用 M1 的 op01（无角色）登录验证按钮缺失与页面可见（菜单绑 `addon.demo.note.index`，无权限者整页不可见——用 op01 验证侧边栏无"演示插件"）
5. **组件缺失兜底**：psql 向 menus 插一条 `addon_key='demo', view_path='ghost/index'` 的菜单 → 重登录刷新 → 侧边栏出现该项 → 点击渲染"组件缺失，请重新构建"提示页 → 删除该菜单行恢复
6. 截图存 `gui-test-screenshots/m3_*.png`

- [ ] **Step 3: 卸载回路**

```bash
$D "cd /var/www/server && php artisan addon:disable demo && php artisan addon:uninstall demo"
ls admin/src/addons    # demo 目录已删（目录可能不存在或为空）
```
浏览器刷新验证菜单消失；再 `addon:install demo` 恢复完整（页面、菜单、接口）。

- [ ] **Step 4: 更新 AGENTS.md**

「常用命令」追加：

```markdown
- 前端测试：`cd admin && npm test`（Vitest，router 映射等纯函数单测）
```

「工作区定位」结构图 `admin/` 行补注：`admin/src/addons/` 为插件安装时生成的产物（gitignore），源码在 `addons/<key>/admin/`。

- [ ] **Step 5: wrap-up 验收记录 + 最终提交**

`docs/superpowers/plans/2026-09-15-arkadmin-m3-wrapup.md`：记录前后端测试数字、GUI 走查 6 项结果、构建提示输出样例、遗留（生产构建流程文档化归 M4+、多语言目录仅 zh-cn）。然后：

```bash
docker compose -f docker/docker-compose.yml exec -u 1000:1000 php sh -c "cd /var/www/server && php artisan test"
git add AGENTS.md docs
git commit -m "docs(m3): frontend sync acceptance records"
git log --oneline
```

---

## 范围外（显式排除）

- 生产环境"一条命令构建"流程文档化/CI 自动构建（§11 对策，归后续运维里程碑）
- 多语言目录（lang/en.ts 等）——当前框架仅 zh-cn，插件跟随；出现第二个 locale 时再扩展合并逻辑
- HTTP 插件管理界面（backlog 候选，M2 wrap-up 已记录）
- admin/dist 构建产物进 git 或云端预编译（已接受代价，§1 决策 2）

## Self-Review 记录

- **Spec 覆盖**：§6.6 复制/删除/构建提示/热更新 → T1（复制删除提示）+ T4 Step 1-2（dev 热更新即真实页面直接生效）；§7.2 插件 glob/`addon_key + view_path` 定位/缺失兜底 → T2；§7.3 i18n 命名空间合并 → T3；§10 M3 完成标志 → T4 Step 2（demo 真实 Vue 页面跑通）。M1 遗留的"管理员列表角色回填简化项"不在本里程碑范围。
- **占位符扫描**：T1 Step 3 含两版 purgeFrontend（初稿误用测试函数、随即给出正确内联版——保留该过程防止执行者犯同样错误）；所有代码完整；无 TBD。
- **类型一致性**：`resolveView(m, appV?, addonV?)`/`ViewMap`/`missingView` 在 T2 定义、T3 页面不经手（mapMenusToRoutes 内部消费）；`noteApi.list(): { list: Note[] }` 与便签页 `rows.value = (await noteApi.list()).list` 一致；i18n 键 `demo.note.*` 在 lang/zh-cn.ts 与页面 t() 调用一致；后端 `frontendDir(string)` 与命令/测试调用一致。
