# ArkAdmin（方舟）设计文档

> 基于 Laravel 的插件化后台框架 —— 参照 FastAdmin 的产品形态与 NiuShop 的插件机制重新实现
>
> 日期：2026-09-14 ｜ 状态：待用户审阅 ｜ 参考仓库 `fastadmin/` 与 `niushop/` 位于本工作区内，新项目将独立建仓

## 1. 背景与目标

用户需要一个**自用的插件化后台底座**，在其上以插件形式逐步构建商城、ERP、MES、CMS 等业务系统。

参考对象与借鉴边界：

| 参考项目 | 借鉴内容 | 许可约束 |
|---|---|---|
| FastAdmin（本仓库） | 产品形态：CRUD 生成器思想、权限/菜单模型、双账号体系 | Apache-2.0，机制与代码均可借鉴 |
| NiuShop / niucloud-admin | 插件机制：目录结构、生命周期、动态路由、前端同步、兼容版本声明 | 官方协议禁止衍生框架发布，**只学机制，严禁抄代码** |

**已确认的关键决策**（头脑风暴结论）：

1. 插件全部自研；只做本地安装/卸载/启停机制，不做在线商店平台
2. 后台 UI 采用 NiuShop 式前后端分离：Vue3 SPA + RESTful API，**接受"装插件需重构建 admin"的代价**
3. 定位为自用底座：务实优先，不为通用性做过度抽象
4. 首个插件为 CMS（纯后台、无移动端），作为插件架构的端到端验收

## 2. 非目标（明确不做）

- 在线插件市场（发布/下载/授权/计费平台）
- 移动端（uni-app/H5/小程序）——留给未来商城插件立项时再引入
- 多租户 SaaS 化
- 插件代码沙箱隔离——插件是自研可信代码，直接以框架权限运行
- 兼容 FastAdmin/NiuShop 插件格式——它们只作参考，不消费

## 3. 技术选型

| 层 | 选型 | 版本基线 |
|---|---|---|
| 后端框架 | Laravel（API-only，无 Blade 页面） | 12.x |
| PHP | | 8.3+ |
| 数据库 | PostgreSQL | 16 |
| 后台认证 | Laravel Sanctum（Bearer Token，无状态） | — |
| 权限 | spatie/laravel-permission + 自研菜单树 | 6.x |
| 后台前端 | Vue 3 + TypeScript + Element Plus + Vite + Pinia + vue-router 4 + vue-i18n | Vue 3.4+/TS 5+ |
| 后端测试 | Pest | 3.x |
| 前端测试 | Vitest | — |
| 代码规范 | Laravel Pint（后端）、ESLint（前端） | — |
| 开发环境 | Docker Compose：php-fpm + nginx + postgres + node | — |

## 4. 总体布局（Monorepo）

```
arkadmin/
├── server/                  # Laravel 后端
│   ├── app/
│   │   ├── Admin/           # 管理员端：Http/Models/Services
│   │   ├── Support/         # 插件系统核心（引导、生命周期、菜单服务）
│   │   └── ...
│   ├── addons/              # 插件根目录（也可软链到仓库根 addons/，见 §6.1）
│   └── ...
├── admin/                   # Vue3 后台 SPA
│   └── src/
│       ├── app/             # 框架核心页面：登录/仪表盘/权限/插件管理/设置/素材库
│       └── addons/          # 插件前端（安装时自动生成，见 §6.6）
├── addons/                  # 各插件完整源码（后端+前端+迁移）
│   └── cms/
├── docs/                    # 文档（含本设计文档）
└── docker/                  # 开发环境编排
```

> 插件源码集中放在仓库根 `addons/`，`server/addons` 为指向它的符号链接，保证 Laravel 与前端同步流程都能以简单路径访问。

## 5. 后端设计（server/）

### 5.1 应用结构

- `app/Admin/Http/Controllers/`：框架自带控制器（认证、管理员、角色、菜单、插件管理、设置、素材、仪表盘）
- `app/Admin/Services/`：业务服务层（控制器瘦、服务厚，参照 NiuShop 分层）
- `app/Support/Addon/`：插件系统核心（§6）

### 5.2 API 约定

- 前缀分组：`/api/admin/*`（管理端）、`/api/app/*`（预留会员/开放端，v1 仅占位）
- 统一响应信封：`{"code": 0, "data": {}, "msg": "ok"}`；`code=0` 成功，非 0 为业务错误码；HTTP 状态码恒为 200，仅认证/参数层错误使用 401/422
- 分页：`data.list` + `data.total` + `data.page/per_page`（NiuShop 同构）

### 5.3 认证与权限

- 管理员登录签发 Sanctum Personal Access Token，前端 Bearer 携带
- RBAC 采用 spatie/laravel-permission（roles/permissions 及中间表），权限串约定 `addon.<key>.<controller>.<action>`，如 `addon.cms.article.index`；框架自身权限用 `system.<controller>.<action>`
- 菜单独立建表（不塞进 spatie），见 §5.4，菜单项与权限串绑定（一个菜单可绑多个权限串）

### 5.4 核心数据表

| 表 | 用途 | 关键字段 |
|---|---|---|
| `admins` | 管理员 | username, name, password, status |
| spatie 系列表 | 角色/权限 | 按 spatie 默认迁移，permission 增加 `module` 列标记归属插件 |
| `menus` | 后台菜单树 | id, parent_id, name, title, icon, route_path, view_path, permission(串), addon_key, sort, is_show |
| `addons` | 插件注册表 | name(唯一), title, version, config(jsonb), enabled, install_time |
| `settings` | 站点设置 | key, value(jsonb), addon_key |
| `attachments` | 素材库（框架级） | name, path, disk, mime, size, width, height, uploader_type |
| `admin_op_logs` | 操作日志 | admin_id, route, method, params(jsonb), ip, created_at |

约定：

- 所有业务表使用 Postgres 原生类型：状态/标签用 `text[]` 或 `jsonb`，**禁止沿用 MySQL 的逗号分隔字符串习惯**（FastAdmin 的 `FIND_IN_SET` 反模式）
- 插件表名前缀 `<key>_`（如 `cms_article`）。曾评估"每插件独立 PG Schema"方案，因 Eloquent/search_path 集成复杂度放弃，v1 用前缀；schema 方案记为未来优化项

### 5.5 事件（钩子）埋点

框架核心预埋 Laravel Event，供插件监听（对应 FastAdmin `Hook::listen`）：

`admin.login.successed`、`admin.op.logged`、`attachment.saved`、`user.register.successed`（预留）等。事件清单在框架文档中维护，新增埋点视为框架公开接口。

## 6. 插件系统设计（核心）

### 6.1 插件目录结构

```
addons/cms/
├── info.json                # 清单
├── src/                     # PHP（命名空间 Addons\Cms\）
│   ├── AddonServiceProvider.php
│   ├── Addon.php            # 实现 ArkAdmin\Support\Addon\Contracts\Lifecycle
│   ├── Http/Controllers/
│   ├── Models/
│   ├── Services/
│   └── Listeners/
├── routes/admin.php         # 管理端路由
├── database/
│   ├── migrations/          # 插件迁移（cms_ 前缀表）
│   └── menus.php            # 菜单定义（返回数组）
└── admin/                   # 前端（安装时复制到 admin/src/addons/cms/）
    ├── views/               # Vue 页面（.vue）
    ├── api/                 # 接口封装（.ts）
    └── lang/                # i18n 语言包
```

### 6.2 info.json 清单

```json
{
  "name": "cms",
  "title": "内容管理",
  "description": "文章、栏目、素材管理",
  "version": "0.1.0",
  "type": "app",
  "support_version": "0.1.0",
  "dependencies": []
}
```

`support_version`：插件要求的框架最低版本（NiuShop 兼容声明机制），安装时校验 `config('arkadmin.version') >= support_version`，不满足则拒绝安装。`type` 当前仅有 `app`（业务插件），预留扩展。`dependencies` 为插件间依赖（v1 仅校验均已安装，不做依赖树解析）。

### 6.3 生命周期

`Addon.php` 实现接口：

```php
interface Lifecycle
{
    public function install(): void;    // 迁移后执行：种子数据、初始化配置
    public function uninstall(): void;  // 迁移回滚后执行：清理
    public function enable(): void;
    public function disable(): void;
    public function upgrade(string $fromVersion): void;
}
```

CLI（Artisan 命令）：

```
php artisan addon:list
php artisan addon:install {name}        # 注册→跑迁移→写菜单→复制前端→提示重构建
php artisan addon:uninstall {name}      # 反向清除（--keep-data 可保留业务表）
php artisan addon:enable|disable {name}
```

状态机：`未安装 → (install) → 已启用 ⇄ (enable/disable) 已禁用 → (uninstall) → 未安装`。仅 `已启用` 状态的插件参与路由/事件/菜单渲染；`已禁用` 保留数据与代码但不加载。

### 6.4 引导流程

框架 `AppServiceProvider::boot()`（或独立的 `AddonBootServiceProvider`）：

1. 扫描 `addons/*/info.json`，得磁盘插件清单
2. 读取 `addons` 注册表，标记 installed/enabled
3. 对 enabled 插件：注册其 `AddonServiceProvider`（插件命名空间 `Addons\` 已由根 composer.json 的 psr-4 映射覆盖，无需动态 autoload）
4. 插件 Provider 内完成：挂载路由（自动前缀 `/api/admin/addon/<key>` + 中间件组）、注册事件监听、注册菜单渲染数据源

性能：enabled 插件清单与事件监听映射写入缓存（`php artisan addon:cache`），生产环境免每次磁盘扫描；`config:cache`/`route:cache` 兼容性在 M2 验证。

### 6.5 菜单注入与清除

- 安装：执行 `database/menus.php` 返回的数组写入 `menus`，写入时强制覆盖 `addon_key = <key>`
- 菜单项字段与框架菜单一致（§5.4），`view_path` 指向插件前端组件相对路径（如 `article/index`）
- 卸载：`DELETE FROM menus WHERE addon_key = <key>`，同时删除 spatie 中 `module = <key>` 的权限
- 前端菜单接口返回整棵树（框架菜单 + 各 enabled 插件菜单按 sort 合并）

### 6.6 前端同步（NiuShop 机制复刻）

- `addon:install` 将 `addons/<key>/admin/` 复制到 `admin/src/addons/<key>/`；`uninstall` 删除之
- 命令结尾输出提示：`请执行 cd admin && npm run build 完成后台构建`
- 开发环境热更新：`admin/src/addons/` 为普通源码目录，Vite dev 直接生效，无需构建

### 6.7 打包分发（预留）

v1 不实现 zip 打包与在线安装；但目录结构与 info.json 设计已为其预留（拷贝目录 + 执行 install 即可），未来加市场不改架构。

## 7. 前端设计（admin/）

### 7.1 结构

```
admin/src/
├── app/                  # 框架核心
│   ├── views/            # login/dashboard/admin/role/menu/addon/setting/attachment
│   ├── api/              # 框架接口封装
│   ├── stores/           # Pinia：user/menu/app
│   ├── router/           # 动态路由构建（见 7.2）
│   └── lang/
└── addons/<key>/         # 插件（安装时生成）
    ├── views/  api/  lang/
```

### 7.2 动态路由（NiuShop 原理）

1. 登录后调用 `GET /api/admin/menus/tree`，后端按当前管理员权限裁剪返回
2. 前端构建路由：框架菜单映射 `import.meta.glob('@/app/views/**/*.vue')`，插件菜单映射 `import.meta.glob('@/addons/**/views/**/*.vue')`，以菜单的 `addon_key` + `view_path` 定位组件
3. 404 兜底：未匹配的 view_path 渲染"组件缺失，请重新构建后台"提示页（对应插件已装但 admin 未重构建的场景）

### 7.3 其他

- i18n：框架语言包 + 插件语言包按命名空间合并（`cms.article.title`）
- 请求层：axios 实例统一处理 code!=0 的 ElMessage 报错与 401 跳登录
- 权限按钮级控制：`v-permission="'addon.cms.article.store'"` 指令

## 8. 开发环境（docker/）

- 服务：`php`（8.3-fpm，装 pdo_pgsql/bcmath/gd/redis）、`nginx`（server 挂 server/public）、`postgres`（16，库名 arkadmin）、`node`（20，常驻 `npm run dev`）
- 镜像走国内加速（`docker.1panel.live` 已验证可用）；composer 用阿里云镜像、npm 用腾讯镜像
- `docker-compose.yml` 置于仓库根 `docker/`，`.env` 提供 DB 连接

## 9. 首个插件：CMS（端到端验收标准）

功能：栏目（树形）、文章（富文本、封面、栏目归属、状态）、素材库消费（上传为框架级能力，见 §5.4 attachments）。

**验收流程（插件系统的验收）**：

1. `php artisan addon:install cms` → 迁移执行、菜单写入、前端文件复制
2. `cd admin && npm run build` → 刷新后台，CMS 菜单出现
3. 文章/栏目 CRUD、上传素材、绑定权限到角色后按权限裁剪菜单
4. `php artisan addon:disable cms` → 菜单消失、路由 404、数据保留
5. `php artisan addon:enable cms` → 完整恢复
6. `php artisan addon:uninstall cms` → 菜单/权限/插件表清除，`cms_*` 业务表回滚；admin/src/addons/cms 删除
7. Pest 测试覆盖上述 1-6 步的自动化版本（安装→操作→卸载全回路）

## 10. 里程碑

| 里程碑 | 内容 | 完成标志 |
|---|---|---|
| M1 框架骨架 | docker 环境、Laravel API 骨架、登录/Token、管理员/角色/权限 CRUD、menus 表与菜单接口、admin SPA 登录+动态路由+核心页面 | 用超管账号完成一次权限受控的 CRUD |
| M2 插件系统核心 | §6 全部：引导、生命周期、CLI、菜单注入、缓存；Pest 全回路测试 | 手工建一个空插件走完装/停/卸 |
| M3 前端同步 | 前端复制/删除、组件缺失兜底、i18n 合并 | M2 空插件带上一个 Vue 页面跑通 |
| M4 CMS 插件 | §9 功能与验收流程 | §9 全部通过 |
| M5 CRUD 生成器 | `php artisan ark:crud --table=cms_xxx`：读 PG information_schema，生成迁移外全套代码（Controller/Service/Model/Request + Vue 页面 + 菜单定义 + 语言包），FastAdmin `crud` 的 Laravel 版 | 对新表一条命令生成可用的完整模块 |

每个里程碑单独走"计划→实现→验收"循环。

## 11. 风险与对策

| 风险 | 对策 |
|---|---|
| 装插件需重构建 admin（已接受的代价） | 文档化一条命令流程；生产可后续加"云端预编译"或 CI 自动构建 |
| spatie permission 与菜单树组合的自研胶水层出 bug | M1 就为其写权限裁剪的 Pest 用例 |
| 插件启停与 Laravel `config/route/event` 缓存的兼容 | M2 明确验证清单：每种缓存开启时装/停/卸各走一遍 |
| 误抄 NiuShop 代码引发协议问题 | 规则写入贡献约定：只读其源码理解机制，产出代码零复制；FastAdmin（Apache-2.0）无此限制 |
| 自研插件引导层性能与健壮性 | 缓存机制（§6.4）+ 安装回路测试（§9.7） |

## 12. 参考资料

- FastAdmin 参考仓库：`fastadmin/`（工作区内，分支 1.6.x-dev）
  - 权限模型：`fastadmin/application/admin/library/Auth.php`；CRUD 生成器：`fastadmin/application/admin/command/Crud.php`（M5 重点研读）
- NiuShop 参考仓库：`niushop/`（工作区内，浅克隆，最新提交 91ef604）
  - 插件安装服务：`niushop/niucloud/app/service/core/addon/CoreAddonInstallService.php`；动态路由：`niushop/admin/src/router/routers.ts`（只读参考，禁抄）
- FastAdmin 文档：https://doc.fastadmin.net ；NiuShop 手册：https://www.kancloud.cn/niucloud/niucloud-admin-develop/3153336
