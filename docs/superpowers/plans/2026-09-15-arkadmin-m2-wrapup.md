# ArkAdmin M2 插件系统核心 — 验收记录

> 日期：2026-09-15 ｜ 计划：`2026-09-15-arkadmin-m2-addon-core.md` ｜ 状态：**完成**

## 里程碑完成标志

设计文档 §10 M2 完成标志"**手工建一个空插件走完装/停/卸**"——以仓库内置 `addons/demo/`（M2 期间手工创建：info.json + src/ + routes + 迁移 + 菜单/权限定义 + 监听器）在开发库完整走通，全部实测通过。

## 自动化测试

`php artisan test`（docker 内，arkadmin_test 库）：**83 passed, 212 assertions**（M1 基线 44 + M2 新增 39）。

M2 新增测试文件：

| 文件 | 覆盖 |
|---|---|
| `Addon/AddonRegistryTest.php`（3） | addons 表、模型主键/cast、框架版本配置 |
| `Addon/AddonInfoTest.php`（8） | info.json 解析与全部校验分支、support_version 比较 |
| `Addon/BootTest.php`（4） | Addons\ autoloader、启用挂载/未启用不挂载、boot 幂等 |
| `Addon/AddonLifecycleTest.php`（9） | 安装/菜单树裁剪/403/停用/恢复/状态机/卸载/keep-data/重装 |
| `Addon/AddonCommandTest.php`（9） | 7 命令成功与失败路径、support_version/依赖拒绝 |
| `Addon/AddonCacheTest.php`（4） | 编译缓存生成/冲掉/清除/坏缓存降级 |
| `Addon/AddonEventTest.php`（2） | 登录事件→插件监听→卸载后摘除 |

## 手工验收（开发库 arkadmin，docker 实测）

1. `addon:list` → demo 未安装 ✓
2. `addon:install demo` → 注册表 enabled=t、menus 2 行（addon_key=demo）、permissions 2 条（module=demo）、demo_notes 表建立 ✓
3. curl 实测：`GET /api/admin/addon/demo/notes` code 0；`POST` code 0；`/auth/me` menus 含"演示插件"（排序在系统管理之后）✓
4. `addon:disable demo` → notes 404 信封、菜单树无演示插件、menus 行仍 2、demo_notes 保留 ✓
5. `addon:enable demo` → 接口与菜单完整恢复、数据保留 ✓
6. `addon:disable` + `addon:uninstall demo` → addons/menus/permissions 清零、demo_notes 回滚 ✓
7. 登录事件：插件安装期间每次登录 demo_notes 写入一条 `login:admin`（插件监听框架 `admin.login.successed` 端到端生效）；卸载后登录正常且不再写入 ✓

## 三缓存矩阵（§11 风险对策验证）

`config:cache + event:cache + route:cache` 全开状态：

- `addon:install demo` → 命令自动重建三项缓存，notes 接口 code 0 ✓
- `addon:disable demo` → 404 ✓；`addon:enable demo` → code 0 ✓；`addon:uninstall demo` → 404 + 清理干净 ✓
- `optimize:clear` 恢复无缓存 ✓
- `addon:cache` 编译缓存 + `docker compose restart php` → php-fpm 从 `bootstrap/cache/addons.php` 引导，登录与插件接口正常 ✓
- 收尾 `addon:clear` ✓

## 实现要点（对计划的落地偏差记录）

1. **引导容错**：`AddonManager::enabledInfos()` 捕获 QueryException——`php artisan test`/`migrate` 等命令引导时 addons 表可能不存在，降级空清单而非启动失败
2. **事件监听注册**：provider 基类逐个注册监听器字符串（整组数组会被 Laravel Dispatcher 当 `[class, method]` 解析报"Undefined array key"）
3. **进程内摘除**：disable/uninstall 时按插件 listen 映射 `Event::forget`；路由摘除以进程重启为准（CLI 生命周期天然短进程，符合预期）
4. `app()->booted()` 是注册回调的写法不是状态查询，改用 `isBooted()`

## 评审轮记录（2026-09-15，新视角代码评审后加固）

评审结论 1 Critical / 3 Important / 9 Minor。核实后接受 1-7、8a/8b、9-12；拒绝 8c（版本号格式校验，v1 YAGNI）与 13（Windows 软链，本工作区 Linux/docker only）。修复六个提交（bfa6e37..9fc7233 后续 docs）：

| 项 | 严重度 | 修复 |
|---|---|---|
| `migrate:rollback --path` 只回滚最后一批，非末批插件卸载静默跳过回滚（vendor `Migrator::getMigrationsForRollback` 核实） | Critical | 改 `migrate:reset --path`（按路径过滤全部已跑迁移）；新增双插件非末批回归测试（先红后绿） |
| install 注册表写入后任一步失败留下半安装态（"已安装"挡死重试） | Important | 注册表+菜单+权限+install 钩子包 `DB::transaction`；迁移留在事务外（重试幂等自愈） |
| 菜单名全局唯一但 `updateOrCreate` 无所有权约束：插件可劫持系统/兄弟菜单并在卸载时误删 | Important | syncMenus 写入前检查 `name` 已被非本插件占用即拒绝 |
| `Event::forget` 按事件整体摘除会杀掉同事件兄弟插件监听 | Important | forget 后从其它启用插件 listen 映射重挂幸存者；双插件监听测试 |
| boot 注册 provider 无容错，坏插件让全站 500 | Minor | try/catch 记日志跳过（"不能拖垮禁用它的人口"） |
| 插件路由缺 `api` 中间件组（无 throttle） | Minor | `Route::middleware(['api', 'auth:admin'])` |
| 清单宽松：name 与目录名可不一致、deps 非字符串条目被静默丢弃 | Minor | fromDir 强制 `name === basename($dir)` + deps 条目校验；暴露并修复 AddonCommandTest 两个空转用例（fixture 目录名不匹配导致 install 在版本/依赖校验前就失败，断言补 `expectsOutputToContain`） |
| 编译缓存的 listeners 字段无人消费 | Minor | compile() 去掉该字段；spec §6.4 勘误（监听注册由 provider boot 完成，不入缓存） |
| MenuService/`addon:list` 查 addons 表无容错 | Minor | QueryException 降级（菜单不过滤禁用插件 / 只列磁盘） |
| 缓存重建失败会把已成功的状态变更报成失败 | Minor | rebuildCache 闭包 try/catch 降级告警 |
| finish() 进程内注册未记账（Octane 陷阱） | Minor | `AddonManager::markLoaded()` |
| ghost 缓存测试 `expect(true)` 空断言 | Minor | 改为断言缓存未含 demo 时其路由确实未挂载（绕开 installer 即时注册，缓存为唯一来源） |

修复后全量 **91 passed, 240 assertions**；开发库真机冒烟（install→addon:cache→接口→disable→uninstall 清理干净）通过。

**评审遗留（不修的记录）**：`server/addons` 软链在 Windows 检出会失效（工作区 Linux/docker only）；info.json 版本号格式未校验（v1 容忍）；HTTP 插件管理 API（backlog）落地前必须先有本轮的 detachListeners 修复（进程内 disable 正是那时的真实场景）。

## 遗留与后续

- **M3 前端同步**：`addon:install` 复制 `addons/<key>/admin/` → `admin/src/addons/<key>/`、构建提示、i18n 合并、组件缺失兜底（demo 菜单 view_path=note/index 当前走 M1 的 missing 兜底页）
- **Backlog 候选**：插件管理后台页面（HTTP API + Element 界面，未指派里程碑）；`addon:upgrade` CLI；框架事件清单文档化（§5.5）
- demo_notes 中的"演示插件"菜单在 M3 前端同步完成后即可点进真实页面
