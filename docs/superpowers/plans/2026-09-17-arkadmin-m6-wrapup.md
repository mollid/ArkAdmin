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

## GUI 走查反馈（用户实测，两轮）

第一轮（`8c3ac80`）：
1. 控制台 `[object Promise]` —— `import.meta.glob` 非 eager 返回 loader 函数，直接喂 `<component :is>` 会被当函数式组件、Promise 被渲染成文本；改 `defineAsyncComponent` 包装 + 按 key 缓存。
2. 系统设置「暂无数据」—— 空态误用 `!groups.length`（对象无 length 恒 falsy）；改判 `entries.length` + `loaded` 标记。

第二轮：系统设置**没有保存按钮** —— 该按钮用 `v-permission="'system.setting.update'"`，而 `system.setting.{index,update}` 是 M6 新增的**框架权限**，开发库的 spatie 权限表仍是 M5 时 seed 的：接口侧超管有 `Gate::before` 旁路照常可用，但前端按 `/auth/me` 权限串判断拿不到串 → 按钮被指令移除。**处置**：重跑幂等种子 `php artisan db:seed --force`（补齐权限 + 同步超管 + 跳过已装系统插件），复核超管权限列表 30 条含新串，刷新浏览器生效。已写入 AGENTS.md「升级/新增框架权限后」提醒。

> 这与 M3 发现的「插件新权限未 sync 给超管导致按钮失效」是**同一类坑的框架权限版本**。根因是「框架权限只在 seed 时创建与授予」，建议 M7 纳入升级流程的一条命令（如 `ark:sync`：跑 RbacSeeder + 系统插件安装 + 菜单 upsert），避免每次靠文档提醒。

## 待办与遗留

- GUI 走查清单：① 仪表盘出现「今日动态」卡片（带数据）② 系统设置页改站点名称并保存生效 ③ 操作日志页筛选/参数展开 ④ 无 `addon.op_logs.index` 权限账号：菜单/卡片/页面三者均不可见
- M7（机制完整化）：插件管理 HTTP 界面、`upgrade()` 钩子、依赖拓扑
- widget/schema 暂不入编译缓存（插件数增长时优化）
