# 组D 审计发现 — HTTP 层/前端/生成器

审计基线：后端 `php artisan test` 219 passed / 952 assertions（全绿），前端 `npm test` 30 passed（全绿）。响应信封实际字段为 `{code, data, msg}`（`server/app/Support/Http/Traits/ApiResponse.php:10-18`），前后端一致（`admin/src/app/api/request.ts:18-39` 读写 `body.code`/`body.msg`）。以下按检查单逐项给结论。

---

## D1 信封契约逐端点核对

**结论：整体安全；1 条 Minor（N+1 + 注释失实）。**

**Minor | index() 逐行 dependents() 形成 N+1，与代码注释矛盾 | `server/app/Admin/Http/Controllers/AddonController.php:39`（row 内 155 行调用）+ `server/app/Support/Addon/AddonDependency.php:51-53` | 复现：`GET /api/admin/addons`，列表有 N 个磁盘插件时。控制器 34 行注释称「所有依赖项一次反查，避免逐行 N+1」，但该预取只覆盖 `missing_dependencies`；`row()` 每行调用 `dependents($name, false, $scan)`，而 `dependents()` 虽接收了复用的 `$scan`，内部仍每次执行 `Addon::query()->get()`（AddonDependency.php:51），N 行插件 = N 次注册表全表查询。当前插件量级（个位数）无实际影响，纯性能瑕疵 + 注释误导。**

逐端点核对（确认安全）：
- `index`（AddonController.php:27-56）：无 try/catch，但异常面已封死——`AddonManager::scan()` 对坏清单逐个捕 `AddonException` 跳过并记日志（AddonManager.php:44-47），`glob()` 返回 false 由 `?: []` 兜底（AddonManager.php:41）；`AddonInfo::fromDir` 所有失败分支均只抛 `AddonException`（AddonInfo.php:28-72，逐行核对无裸 RuntimeException/JsonException）。残余风险仅剩 `Addon::all()`/`dependents()` 的 QueryException（DB 宕机/未迁移），属基础设施故障，不在「业务恒 200」契约射程内。`data` 结构：正常行 13 字段（row()，142-157 行）∪ 注册表有磁盘缺的只读行（含 `disk_missing: true`，43-53 行）。
- `store`（58-76 行）：validate→422（文档化例外）；`AddonException`→fail(1)；`\Throwable`→report+fail(1)（65-70 行）；成功返回 `needs_build` 取自 `lastSyncedFrontend`，前端同步失败时为 null → 不谎报需构建（AddonInstaller.php:187-188、225-282），语义正确。
- `update`（79-110 行）：`action` 白名单校验（82-84 行），100 行 `$this->installer->{$action}($addon)` 仅在 action∈{enable,disable} 时可达（upgrade 在 92-99 行提前 return），动态调用无注入面；系统插件 disable 前置拦截 fail(1)（88-90 行），绕过界面打接口被后端硬拦。三条路径（AddonException/\Throwable/成功）全部收进信封。
- `destroy`（112-128 行）：系统插件拦截 fail(1)（114-116 行）；`uninstall` 双异常捕获齐全（119-125 行）。
- 全局兜底（`server/bootstrap/app.php:54-67`）：AuthenticationException→401、ValidationException→422、HttpExceptionInterface→信封（HTTP 状态仅 401/422 例外，其余恒 200），与契约一致。插件路由挂载带 `['api','auth:admin']` 包裹（`server/app/Support/Addon/AddonServiceProvider.php:52-54`），生成路由自带 permission 中间件，无漏网。
- 路径安全：`{addon}` 路由参数进 `mustExistOnDisk` 被 `^[a-z][a-z0-9_]*$` 卡死（AddonInstaller.php:328-330），`..`/`%2F` 均被拒，无目录穿越。

---

## D2 keep_data 查询参数完整流转

**结论：确认安全，全链语义一致。**

流转核对：前端 `addonApi.uninstall(name, keepData)` 仅在 `keepData=true` 时发 `{ params: { keep_data: 1 } }`，否则空 params（`admin/src/app/api/addon.ts:11-12`）→ 路由 `Route::delete('addons/{addon}', ...)`（`server/routes/api.php:50`）→ `destroy()` 用 `$request->boolean('keep_data')`（AddonController.php:118）→ `uninstall(string $name, bool $keepData = false)`（AddonInstaller.php:95）→ `$keepData=false` 时 `migrate:reset` 回滚业务表，true 时跳过回滚但菜单/权限/注册行/前端产物照常清除（AddonInstaller.php:115-128）。

类型转换反证：`Request::boolean()` 底层 `filter_var(FILTER_VALIDATE_BOOLEAN)`——'1'/'true'/'on'/'yes'→true；'0'/'false'/'off'/'no'/缺省→false；缺省值语义=「全部回滚」（先删数据），与前端默认 confirm 按钮（'全部回滚'）和 CLI 默认（`addon:uninstall` 不带 `--keep-data` 即回滚，AddonUninstallCommand.php:11、19）三处一致。前端发送数字 `1` 序列化为 `keep_data=1`→true，无歧义值路径；发 '0' 得 false 亦安全（方向保守：只有显式真值才保留数据）。

---

## D3 前端 AddonRow 类型与后端 row() 字段漂移

**结论：确认安全，无漂移。**

逐字段比对（后端 `AddonController.php:142-157` + 磁盘缺失分支 43-53 行 ↔ 前端 `admin/src/app/utils/addon.ts:1-16`）：

| 字段 | 后端 | 前端 | 一致 |
|---|---|---|---|
| name/title/description | string（description 来自 info.json `(string) ?? ''`） | string | ✓ |
| version | string（AddonInfo 校验过 x.y.z 格式） | string | ✓ |
| installed_version | `$record?->version`，未安装为 null | `string \| null` | ✓ |
| installed | `$record !== null` | boolean | ✓ |
| enabled | `(bool) $record?->enabled`（模型 cast boolean，Addon.php:24） | boolean | ✓ |
| upgradable | version_compare 结果 | boolean | ✓ |
| system | in_array 严格比较 | boolean | ✓ |
| dependencies/missing_dependencies/dependents | string 数组（`array_values` 归一） | string[] | ✓ |
| install_time | `?->toDateTimeString()`，未安装为 null | `string \| null` | ✓ |
| disk_missing | 仅磁盘缺失行返回 true，正常行键缺席 | 可选 `disk_missing?: boolean` | ✓（缺席=undefined=falsy，与 addonStatus 判定兼容） |

磁盘缺失行的 13 个基础字段与正常行同构（43-53 行硬编码补齐），前端无需特判。addonStatus 优先级（磁盘缺失 > 缺依赖 > 未安装 > 可升级 > 启用/禁用，utils/addon.ts:24-32）与后端状态语义一一对应。

---

## D4 confirmUninstall 三选语义与后端对齐

**结论：核心语义对齐；2 条 Minor（提示文案漂移）。**

**Minor | 升级空跑时前端提示「升级成功」与后端「已是最新版本」漂移 | `admin/src/app/views/system/addon/index.vue:96` ↔ `server/app/Admin/Http/Controllers/AddonController.php:95-98` | 复现：列表加载后（row.upgradable=true 显示升级按钮）、点击前该插件被另一端升级完 → 后端返回 `{upgraded:false, msg:'已是最新版本'}`，但前端 `run()` 无条件 `ElMessage.success('升级成功')`（拦截器只对 code≠0 弹错）。并发窗口窄，属文案级瑕疵。**

**Minor | 卸载弹窗正文对「保留数据」分支不准确 | `admin/src/app/views/system/addon/index.vue:110` | 复现：点卸载 → 弹窗正文恒为「卸载将回滚插件业务表…」，但选「保留数据」时业务表并不回滚（仅菜单/权限/注册行清除，AddonInstaller.php:115-128）。按钮文案（'全部回滚'/'保留数据'）已明确区分，正文描述的是默认分支后果，故仅 Minor。建议正文改为「默认回滚插件业务表（可选保留）」之类。**

其余对齐点（确认安全）：
- 三选语义：confirm='全部回滚'→keepData=false；cancel='保留数据'→keepData=true；close/ESC（`distinguishCancelAndClose: true`）→null 放弃（index.vue:106-119）。传参链与 D2 一致；两条路径结果提示统一为「卸载成功」（index.vue:102），无歧义。
- dependents 提示口径：弹窗「正被 X 依赖」用的是后端 `row.dependents`（index() 以 `enabledOnly=false` 计算，AddonController.php:155），与 uninstall 实际拦截口径（AddonInstaller.php:111 同为 `enabledOnly=false`，禁用态依赖方也挡）完全一致——提示不会漏报。
- 系统插件保护：前端对 system 行隐藏禁用/卸载按钮（index.vue:47、51 `!row.system`），后端 update/destroy 双硬拦（AddonController.php:88-90、114-116）；绕过界面直接打接口 → fail(1)「请使用 CLI 操作」由拦截器 `ElMessage.error` 展示。系统插件被 CLI 停用后 HTTP 启用通道保留（update 仅拦 disable），与 AGENTS.md「HTTP 界面禁止停用/卸载」边界吻合。
- 磁盘缺失行：前端无任何操作按钮（index.vue:42-44），后端 `mustExistOnDisk` 兜底拒绝，双侧一致。

---

## D5 ark:crud 生成产物幂等性

**Important | 追加类目标文件缺失时生成语法非法的裸块文件 | `server/app/Support/Crud/CrudGenerator.php:269-288`（writeMarkerRegion）| 复现：准备一个插件——有 `database/menus.php`（根 children 内已植入 `// ark:crud:menus:start/end`，这是文档唯一要求的手工标记）但没有 `database/permissions.php`（AddonInstaller 对其缺失按 `[]` 处理，属合法可选文件，AddonInstaller.php:390-392）→ 对既有表跑 `php artisan ark:crud --table=xxx_yyy --addon=<key>`。此时 `$content=''`，`strrpos('', '];')` 返回 false（282 行条件不成立）落入 287 行裸写，产出一个不含 `<?php`、不含 `return [...]` 的 permissions.php，内容只有四个权限串。后果链：① `refreshMenusAndPermissions` 里 `require` 该文件返回 int 1 → `AddonInstaller.php:396` 抛「permissions.php 必须返回字符串数组」→ CLI 仅 warn「代码已生成，但未刷新菜单/权限」，开发者易忽视；② 此后该插件的 `addon:install`/`upgrade` 在 declaredPermissions 处整体失败（AddonInstaller.php:81-83、507-509），插件卡死，报错误导（指向文件格式而非生成器半成品）；③ 同理 `routes/admin.php` 缺失 → 生成的无 `<?php` 路由文件被 provider `Route::group(require)` 时静默不注册路由（include 返回 1 且把 PHP 代码当文本输出，AddonServiceProvider.php:44-55），路由无声消失；④ `admin/lang/zh-cn.ts` 缺失 → 裸 TS 块无 `export default`，Vite 构建报错（尚可见）。修复方向：与 precheckMenus 同构，生成前对三个追加类文件做存在性/骨架校验，缺失时明确报错或生成合法骨架。**

**Minor | 同插件内 snake 归一撞车时 --force 静默覆盖前表模块 | `server/app/Support/Crud/CrudGenerator.php:93-114`（names）| 复现：同插件先后对 `cms_article` 与 `cms_articles` 生成（二者 singular→studly 同为 `Article`，model/viewDir/menuName/perm/file 路径全同）。第一次后跑第二次：无 --force 时 46-52 行文件存在检查先挡，报错清晰（安全）；加 `--force` 则 7 个整文件被静默覆盖为后表结构，且 menuName `cms.article` 相同——syncMenus 的菜单占用检查只拦「其它 addon_key」（AddonInstaller.php:354-359），同插件内 updateOrCreate 直接复用同一菜单行并覆盖 route_path，前表菜单与权限串（`addon.cms.article.*`）归并。属 CLI 开发者工具的脚枪，需人为构造同 snake 表名，故 Minor；可考虑在 names() 检测到目标 model 文件已存在且 --force 时提示「表名归一冲突」。**

幂等机制本身（确认安全）：
- 菜单标记对：`precheckMenus`（CrudGenerator.php:318-329）先于任何写入执行——本表标记或通用标记对任一存在才放行，缺失即抛 CrudException 并给出植入示例，杜绝半生成态；`writeMenusEntry`（295-315 行）本表标记存在则整块替换，否则在首个 `// ark:crud:menus:end` 前插入（preg_replace limit=1），cms 实文件形态验证落位正确（addons/cms/database/menus.php:22-23）。
- per-table 标记对（routes/permissions/lang）：`writeMarkerRegion` 整块替换正则 `/start.*?end/s`（277 行），块内容不含标记串，无嵌套吞噬风险；重复生成标记数恒为 1（`server/tests/Feature/Crud/CrudGeneratorTest.php:86-95` 已固化验证）。
- 无 --force 重复生成：7 个整文件存在性检查（46-52 行）先于全部写入 → 整次运行中止，不会出现「整文件报错但标记块已替换」的混合态。
- 权限串命名：`addon.<addon>.<snake>`（names():110）自带插件前缀，跨插件不冲突；写入走 `firstOrCreate` + 超管增量授予（AddonInstaller.php:402-419），重复执行幂等；同名菜单跨插件冲突被 syncMenus 硬拦（AddonInstaller.php:354-359）。
- 路由块安全：生成的路由只带 permission 中间件，auth:admin 由基类 `mountRoutes` 统一包裹（AddonServiceProvider.php:52-54），与 cms 手写路由（addons/cms/routes/admin.php:7 注释）同机制，无鉴权缺口。

---

## D6 --force 重复生成安全性

**结论：覆盖语义已声明、stubs 无危险内容，确认安全；2 条 Minor。**

**Minor | file_put_contents/@mkdir 返回值未检查，失败时谎报「已生成」 | `server/app/Support/Crud/CrudGenerator.php:56-60` | 复现：目标目录只读或磁盘满时跑 ark:crud，`@mkdir` 抑制、`file_put_contents` 返回 false 不抛 → 命令照常输出「已生成：…」（ArkCrudCommand.php:47-49），实际落盘空/缺文件，后续 require 才炸。理论风险（CLI 本地开发环境），Minor。**

**Minor | 生成控制器 findOrFail 的 404 信封直接把英文异常原文透给用户 | `server/stubs/crud/controller.stub:37、55` ↔ `server/bootstrap/app.php:64-66` ↔ `admin/src/app/api/request.ts:21-23` | 复现：GET 一个不存在的 id（如 `/addon/cms/articles/999`）→ ModelNotFoundException → NotFoundHttpException → 全局 handler 返回 HTTP 200 + `{code:404, msg:'No query results for model …'}` → 拦截器成功分支 `ElMessage.error(body.msg)` 直接展示英文模型类名。契约合规（恒 200 信封），纯展示瑕疵；属全站统一行为（框架 AdminController 亦如此），非生成器独有。**

安全性核对（确认安全）：
- stubs 内容审查（7 个全部通读）：无迁移生成、无 DDL/drop table/drop column；`model.stub` 只声明 table/fillable/casts，`service.stub`/`controller.stub` 全是参数化查询（PG 方言 `ilike` + `PgLike::wrap`，service.stub:19-21，PgLike 存在于 `server/app/Support/Http/PgLike.php`）；`update_request.stub` 的「全字段 sometimes」防 PUT 局部更新误清空。唯一的破坏性 DDL（`migrate:reset`）在 AddonInstaller.doUninstall:118，且仅在用户显式选「全部回滚」（HTTP keep_data 缺省）或 CLI 不带 --keep-data 时触发，与 stub 无关。
- --force 覆盖范围：仅 7 个整文件（model/service/controller/2 requests/api_ts/view_vue），签名 help「覆盖已生成文件（标记对内容整块替换）」（ArkCrudCommand.php:21）与 model.stub 注释「可再手工修改，重新生成需 --force 覆盖」（model.stub:8）均已声明覆盖语义 → 手工修改丢失属知情设计，非隐蔽数据损坏。追加类文件（routes/permissions/lang/menus）不受 --force 影响，恒走标记对幂等替换，手工改动安全。
- --force 复跑收尾：仍执行 `refreshMenusAndPermissions`（ArkCrudCommand.php:53），updateOrCreate/firstOrCreate 幂等，不产生重复菜单/权限行。
- 已验证基线：`CrudGeneratorTest.php`（落位/幂等/标记缺失报错/E2E）与全量测试绿，生成物 PHP 语法合法性有测试固化（CrudGeneratorTest.php:14）。

---

## 总结

| 级别 | 数量 | 明细 |
|---|---|---|
| Critical | 0 | — |
| Important | 1 | D5 追加类目标文件缺失时生成语法非法的裸块文件（permissions.php 缺失场景可致插件无法安装/升级） |
| Minor | 6 | D1 index() dependents() 逐行 N+1 与注释矛盾；D4 升级空跑提示「升级成功」漂移；D4 卸载弹窗正文对「保留数据」分支不准确；D5 同插件 snake 归一撞车 + --force 静默覆盖；D6 file_put_contents 返回值未检查；D6 findOrFail 404 英文原文透传 |

信封契约、keep_data 流转、前后端类型契约、三选卸载语义、标记对幂等机制、--force 与 stub 安全性总体过关，无权限绕过与数据损坏路径。最值得修的一条是 D5 的 Important：`writeMarkerRegion` 在目标文件缺失时把裸块当合法文件落盘，会让按文档操作（只给 menus.php 植标记）的开发者拿到一个之后无法安装的插件，且报错误导到文件格式本身。
