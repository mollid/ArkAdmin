# ArkAdmin M6 扩展点 + 系统插件 — 验收记录

> 日期：2026-09-17 ｜ 计划：`2026-09-17-arkadmin-m6-harness.md` ｜ 规格：`specs/2026-09-17-arkadmin-harness.md` ｜ 状态：**完成（GUI 走查待手工补充）**

## 里程碑完成标志

三个扩展点全部落地并被系统插件消费：

1. **Widget**（§3.1）：`AddonManager::widgets()` 运行时收集 `database/widgets.php` → `GET /api/admin/widgets` 按权限过滤 → 仪表盘壳（核心 `dashboard/index.vue`）glob 渲染 `/src/addons/<key>/views/widgets/*.vue`。op_logs 插件的「今日动态」卡片即首个消费者。
2. **Settings**（§3.2）：`settings` 表（key/value(jsonb)/addon_key）+ `SettingStore`（写白名单=schema 声明、归属校验收口）+ `GET/PUT /api/admin/settings`（`system.setting.{index,update}`）；settings 系统插件声明 schema + 管理页。
3. **事件埋点**（§3.3）：`LogAdminOperation` 中间件对写方法自动派发 `AdminOperationLogged`（结果码/耗时/脱敏参数），登录成败由 AuthController 显式派发（中间件跳过 login 路径避免双记）；op_logs 插件只监听存储。

## 自动化测试

- 后端 Pest：**173 passed / 760 assertions**（M5 基线 151 + M6 新增 22：Settings 8 + OpLog 5 + Widget 4 + SystemPlugins 5）
- 前端 Vitest：**27 passed**（+widget 组件寻址 2）、`vue-tsc -b` 通过

## 开发库实测（HTTP 真实链路）

1. `addon:install settings / op_logs` → 菜单「系统设置」「操作日志」就位、前端产物同步 ✓
2. `php artisan migrate --force`（settings 表）——**部署脚本必须包含框架迁移**，漏跑即 500（实测踩到）✓
3. `GET /widgets` → `op_logs.today`；`GET/PUT /settings` → site.name 等归属 system 落库 ✓
4. 登录/写操作自动进 `admin_op_logs`（密码脱敏落库）→ `today` 卡片 operations=7 / logins=4 ✓

## 实现要点与计划偏差

1. **仪表盘壳留在核心**（spec 偏差）：壳无独立业务数据，做成系统插件会破坏「登录默认跳转 /dashboard」与 M1 测试基面；widget 扩展点本身不受影响。规格已修正。
2. **插件命名约束**：插件 key 正则 `[a-z][a-z0-9_]*` 不允许连字符 → `op_logs`（非 op-logs）；命令名 `op-logs:prune` 不受限。
3. **运行时注册的插件命令在测试进程内不可见**（`commands()` 依赖 Artisan 启动时序）——测试用 `Kernel::registerCommand` 显式注册；CLI 全新进程正常。
4. **PG 事务坑**：监听器内 SQL 失败会 abort 整个测试事务（catch 救不回 25P02）——OpLog 模型补 `timestamps=false` 与 `created_at` 显式写入后消除；监听器吞异常只记警告，日志失败不影响业务请求。
5. `AddonManager` 新增 `widgets()/settingSchemas()` 运行时扫描（插件量级小暂不入编译缓存，插件数增长时再优化）。
6. `config('arkadmin.system_addons')` + DatabaseSeeder 幂等自动安装捆绑系统插件（db:seed 即得完整后台）。

## 待办与遗留

- GUI 走查清单：① 仪表盘出现「今日动态」卡片（带数据）② 系统设置页改站点名称并保存生效 ③ 操作日志页筛选/参数展开 ④ 无 `addon.op_logs.index` 权限账号：菜单/卡片/页面三者均不可见
- M7（机制完整化）：插件管理 HTTP 界面、`upgrade()` 钩子、依赖拓扑
- widget/schema 暂不入编译缓存（插件数增长时优化）
