# ArkAdmin M6 扩展点 + 系统插件 — 实施计划

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:executing-plans。Steps 用 checkbox 跟踪。

**Goal:** 落地 harness 三扩展点（Widget / Settings / 事件埋点）与两个系统插件（settings、op-logs），仪表盘改造为 widget 消费壳。

**Spec:** `docs/superpowers/specs/2026-09-17-arkadmin-harness.md`（边界与归属模型以此为准）。

**Architecture:** Widget 与 settings schema 均**运行时扫描声明文件**（`database/widgets.php` / `database/settings.php`，进 AddonManager 编译缓存），不建注册表——声明随插件装卸自然生灭；settings 存取（表+服务+API）在核心，写白名单=schema 声明、归属校验在服务层收口；op-log 埋点 = 核心中间件（terminate 派发 `AdminOperationLogged`，含脱敏）+ AuthController 登录成败显式派发，op-logs 插件只监听存储。仪表盘壳**留在核心**（偏离 spec「dashboard 系统插件」：壳无独立业务数据、插件化会破坏「登录默认跳转」与 M1 测试基面，偏差记录于 wrapup）。

**Global Constraints:** 信封/权限串/表前缀/测试隔离等既有约定不变；系统插件入库 `addons/{settings,op-logs}`、`config('arkadmin.system_addons')` 声明捆绑清单、DatabaseSeeder 幂等自动安装；权限串 `system.setting.{index,update}`、`addon.op-logs.*`、`addon.settings.manage`。

## File Structure

```
server/
├── database/migrations/2026_09_17_000001_create_settings_table.php   # key unique, value jsonb, addon_key index
├── app/Support/Settings/Setting.php                                  # get/set(list) 归属校验收口 + schema 合并
├── app/Support/Crud/…（不动）
├── app/Admin/Http/Middleware/LogAdminOperation.php                   # terminate 派发 AdminOperationLogged（脱敏）
├── app/Admin/Events/AdminOperationLogged.php                         # admin 可空（登录失败）
├── app/Admin/Http/Controllers/SettingController.php                  # GET/PUT /api/admin/settings
├── app/Admin/Http/Controllers/WidgetController.php                   # GET /api/admin/widgets（权限过滤）
├── app/Support/Addon/AddonManager.php                                # + settingSchemas()/widgets()（扫描+编译缓存）
├── bootstrap/app.php                                                 # + 中间件、SettingController 路由在 routes/api.php
├── app/Admin/Seeds/{RbacSeeder,DatabaseSeeder}                       # + system.setting.*；+ 自动安装系统插件
├── tests/Feature/Harness/{SettingsTest,OpLogTest,WidgetTest}.php
addons/settings/    # info.json/schema/管理页/安装回路
addons/op-logs/     # info.json/迁移/监听器/查询页/widget/安装回路
admin/src/app/views/dashboard/index.vue                             # 改造为 widget 消费壳
admin/tests/widget.test.ts                                          # widget 组件寻址纯函数
```

---

### Task 1: Settings 基建（表/服务/schema/API）
- 迁移 settings 表；`Setting` 服务：`all()`（schema 合并当前值）、`setFor(addon, values)`（白名单=该 addon schema 声明、归属校验收口）、`get(key, default)`；`AddonManager::settingSchemas()` 扫描 `database/settings.php`（进编译缓存）。
- HTTP：`GET /api/admin/settings`（system.setting.index，schema+值）、`PUT /api/admin/settings`（system.setting.update，按组批量，服务层归属校验，违规 422 信封）。
- RbacSeeder matrix 增 `setting => [index, update]`。
- Test `SettingsTest`：schema 合并、白名单外 key 拒绝、跨 addon 写拒绝、共用可读、权限 403、重复 PUT 幂等。

### Task 2: op-log 埋点
- `AdminOperationLogged`（admin 可空）+ `LogAdminOperation` 中间件：仅写方法、terminate 派发（status_code/耗时/ip/脱敏参数，遮蔽表 password|token|secret）；注册进 api 组。
- AuthController：登录成功（沿用现有事件外）与**失败**分支派发（admin=null，含 attempted username）。
- Test `OpLogTest`：写操作派发（Event::fake 断言 payload）、GET 不派发、密码脱敏、登录失败派发、未登录不炸。

### Task 3: Widget 机制（核心）
- `AddonManager::widgets()`：扫 enabled 插件 `database/widgets.php`（key/component/title/permission/sort），入编译缓存；`GET /api/admin/widgets`（auth 即可）按用户权限过滤下发。
- Test `WidgetTest`：声明收集、禁用插件不下发、无权限不下发、编译缓存含 widgets、声明非法报错。

### Task 4: settings 系统插件
- `addons/settings`：info.json、schema（示例组：站点名称 site.name 等，addon=settings？——系统共用组归属 `system`）、管理页（动态渲染 schema 类型 text/boolean/number/textarea）、权限 `addon.settings.manage`（菜单挂管理页）。
- Test：安装→schema 进 API→PUT 写入归属 system→卸载后菜单/权限清、settings 表与核心 API 仍在。

### Task 5: op-logs 系统插件
- `addons/op-logs`：迁移 `admin_op_logs`（admin_id nullable, route, method, params jsonb, status_code, ip, created_at）、监听器（AdminOperationLogged/AdminLoginSuccessed→入库）、查询页（筛选 admin/route/时间）、widget 卡片（今日操作数/登录数）、清理 artisan 命令 `op-logs:prune {--days=90}`。
- Test：事件→入库（含登录失败/脱敏落库）、查询页 API 权限、禁用插件后事件仍派发但无监听不炸、卸载回路、reinstall 数据清空。

### Task 6: 仪表盘壳 + 收尾
- `dashboard/index.vue` 改造：拉 widgets 清单→glob 解析 `/src/addons/*/views/widgets/*.vue`→渲染；未命中组件跳过。
- `config('arkadmin.system_addons') = ['settings', 'op-logs']`；DatabaseSeeder 末尾幂等安装。
- Vitest：widget 组件寻址纯函数（`widgetComponentPath(addon, component)`）。全量回归 + `npm run build`。

### Task 7: 验收收尾
- 开发库实装（install 两系统插件、HTTP 走查）、AGENTS.md、wrapup、评审轮。

## 范围外
升级钩子/依赖拓扑/管理界面（M7）；zip 打包；dashboard 插件化（偏差：壳留核心，理由见 Architecture）；素材库插件化（backlog）。
