# 组A 审计发现 — 安装器与生命周期

审计对象：`server/app/Support/Addon/AddonInstaller.php`（全文精读）+ `server/tests/TestCase.php`；按需关联 `AddonManager` / `AddonDependency` / `AddonInfo` / `AddonServiceProvider` / `FrameworkCache` / `Contracts/Lifecycle` / 四个现有插件的 `Addon` 钩子实现 / vendor 锁实现（`Illuminate\Cache\DatabaseLock`、`Lock`、`Events\Dispatcher`）。基线验证：`AddonLockTest` + `AddonListenerDetachTest` + `AddonFrontendSyncTest` 共 13 passed / 37 assertions（本机实测）。

---

## 1. 事务边界：install 的 DB::transaction vs uninstall 的三连删

**结论：确认安全（半程失败均可安全重试），附带 3 条 Minor。**

install 侧反证：迁移在事务外（`AddonInstaller.php:69`）但幂等自愈（重试时 migrate 空转，注释 70-71 固化）；`Addon::create`/`syncMenus`/`createPermissions`/`install` 钩子全部在 `DB::transaction` 内（72-87 行），任一步失败回滚后注册表无残留行，不会出现「已注册但无菜单/权限」挡死重装的半安装态。事务提交后只剩 `finish()`（内部 provider 注册失败已 try-catch 降级为 warning，529-532 行）与 `syncFrontend`（失败仅 warning + `lastSyncedFrontend=null`，安装命令据此如实提示 `AddonInstallCommand.php:28-33`），均为软失败不破坏数据。

uninstall 侧反证：菜单删除/`deletePermissions`/`$record->delete()`（123-125 行）虽然无事务包裹，但三者均幂等且卸载入口无「已是目标状态」拦截（只要注册行还在，重试必然通过 102-114 行的全部前置检查）——半程失败（进程被杀/瞬时 DB 错误）后直接重跑 `addon:uninstall` 即收敛到终态。另外 `deletePermissions` 内部先删两张关联表再删权限行（429-431 行），崩溃窗口只会留下「无关联的孤儿权限行」，方向选对了。

**Minor ① | 磁盘目录被删的已装插件永远无法出清注册表**
位置：`AddonInstaller.php:109`（uninstall）、`:174`（disable）
复现：插件处于启用态时运维直接 `rm -rf addons/foo`（绕过 uninstall 契约）→ boot 期仅 warning 跳过（`AddonManager.php:70-73`）→ 尝试 `addon:disable foo` 在 mustExistOnDisk 处抛「不存在或清单无效」→ 无法 disable；而 uninstall 要求先 disable（106-108 行）→ **禁用/卸载双重死锁**，注册行永久残留为「已启用」。恢复只能手工造一个合法目录（info.json + src/）或手工改库。属边界缺口（触发前提是违背操作契约），建议未来给 CLI 加 `--force` 类清理通道。
待验证说明：逻辑链完全由代码读出，未实测；可按上述序列在测试中复现。

**Minor ② | 跨插件菜单名占用检查存在 TOCTOU，`addon_key` 仍可被并发换主**
位置：`AddonInstaller.php:354-360`；`server/database/migrations/2026_09_14_000002_create_menus_table.php:14`（`name` 唯一索引）
复现：插件 A、B 的 menus.php 声明同名菜单，两进程**同时** `addon:install`（锁键是 per-addon 的 `arkadmin:addon:{name}`，互不阻塞）→ 双方都通过 354-357 行的 occupied 检查 → A 的 `updateOrCreate` 先建行，B 的 `updateOrCreate` 按 name 命中 A 的行走 update 路径（唯一索引不阻止 update），`addon_key` 被改写为 B → 之后卸载 A 时 123 行 `Menu::where('addon_key','a')` 删不到它，B 反而持有 A 的菜单。M8 锁只封了同插件竞态，跨插件不变量仍靠先检查后写入。概率极低（要求两个不同插件声明同名菜单且恰好并发安装），建议后续把占用检查改为事务内 `lockForUpdate` 或以 DB 唯一约束 + 捕获冲突实现。

**Minor ③ | uninstall 钩子可能双重执行；确定性抛错则插件永远卸不掉**
位置：`AddonInstaller.php:122-125`
复现：`hook('uninstall')`（122 行）抛异常 → 迁移已 reset、注册行/菜单/权限原样保留 → 重试时钩子再次执行（Lifecycle 契约只说「清理」，未约定幂等）。若钩子是确定性抛错（如引用已回滚的表），重试必然卡在同一行，插件进入「卸不掉」态，只能改插件代码。现有四个插件钩子均空/幂等删除（`addons/settings/src/Addon.php:15`），实际无害。

---

## 2. enable/disable 的注册表 update 与钩子调用顺序

**发现问题 | Minor | 位置 `AddonInstaller.php:153-154`（enable）、`:180-181`（disable）**
复现路径：插件实现 `Lifecycle::enable()` 且抛异常 → `doEnable` 中 `$record->update(['enabled' => true])`（153 行，单语句 autocommit）已提交 → 钩子异常向上传播，`finish()`/`syncFrontend` 未执行 → 终态：**注册表 enabled=true，但 enable 钩子的自有工作未做**。此时重跑 `addon:enable` 被 142-144 行「已是启用状态」拒绝——**没有直接重试通道**，只能走 disable→enable 反向舞步（每次 hop 再跑一遍另一侧钩子），若 disable 钩子也抛错则两向全堵。disable 对称（update(false) 先于 hook，171-173 行挡重试）。
中间失败窗口的完整状态：菜单/权限不变（install 时落库，disable/enable 周期本就不动它们）；编译缓存通常本就不存在（每次生命周期操作 `flushCompiled` 删除、`FrameworkCache::rebuild` 只管 config/event/route，`AddonManager.php:230-233`）→ 下次请求 boot 走 `enabledInfos()` 按注册表现算，运行态自愈；仅当运维在 disable 与失败的 enable 之间跑过 `addon:cache` 时，才存在「陈旧编译缓存漏掉该插件」的窗口，直到下一次生命周期操作冲掉。
定级理由（Minor 而非 Important）：①「已是目标状态抛异常」是被测试固化的刻意状态机（`AddonLifecycleTest.php:99-105`）；②中间态是连贯的、有恢复路径；③当前全部插件钩子为空实现。改进方向：把「已在目标状态」改为幂等 no-op 返回，或在钩子抛错时给出「请先 disable 再 enable 重试」的指引。

---

## 3. syncFrontend 原子替换三步的失败残留

**结论：确认安全（异常路径完备、可回滚），附带 2 条 Minor。**

反证：tmp 与 backup 都建在 `dest` 同目录（251、261 行）→ 同分区，`rename` 原子；拷贝失败→清 tmp 返回（253-258 行）；让位失败→旧产物原地不动（263-268 行）；新目录就位失败→尝试把 backup 改名回 `dest`（269-274 行）；全部失败均 warning + `lastSyncedFrontend=null`，不谎报。启动时的 `deleteDir($tmp)`/`deleteDir($backup)`（252、262 行）顺带清掉上次崩溃的同 pid 残留。测试覆盖完整（`AddonFrontendSyncTest` 13 条）。

**Minor ① | 硬杀窗口：两次 rename 之间 dest 短暂缺失；`.bak` 残留**
位置：`AddonInstaller.php:263-278`
复现：进程在 263 行（dest→backup）与 269 行（tmp→dest）之间被 kill → `src/addons/<name>` 整体缺失；在 269 与 278 行之间被 kill → 旧副本以 `.bak-<pid>` 残留。注释「任一步失败都能保留/回滚出完整的旧副本」只对异常路径成立，对 SIGKILL/断电不成立。恢复手段现成（enable/refresh 的 syncFrontend 自愈会重建），且窗口为微秒级，故仅 Minor。

**Minor ② | 残留的 `.tmp-<pid>`/`.bak-<pid>` 目录会被前端构建吞进去**
位置：`admin/src/app/lang/index.ts:16`、`admin/src/app/router/resolveView.ts:4`、`admin/src/app/views/dashboard/index.vue:26`
复现：接 Minor ① 残留 `src/addons/cms.bak-123/` → 下次 `npm run build` 时三个 `import.meta.glob`（`/src/addons/*/lang/zh-cn.ts`、`/src/addons/**/views/**/*.vue`、`/src/addons/*/views/widgets/*.vue`）在构建期把残留目录一并编入 bundle：多一份死代码 chunk + zh-cn 下多出 `cms.bak-123` 垃圾 i18n 命名空间（`mergeAddonLangs` 按目录名归并）。功能不破坏（菜单已删、widget 声明已随插件消失，视图不可达），但构建产物被污染且无人报警——deleteDir 全程 `@` 静默。建议清理失败时补一条 warning。

---

## 4. detachListeners 的 Event::forget 窗口

**结论：确认安全（当前架构下窗口内不存在并发注册），附带 2 条契约边界 caveat。**

反证：①「窗口内新事件注册丢失」在单线程 PHP 同步执行段中不成立——`Event::forget`（459 行）与幸存者重挂循环（463-473 行）之间没有任何事件派发或 await，本进程内无代码能插进来注册监听；标准 FPM 每请求全新 app 实例，detach 只影响本次请求，下次 boot 由启用插件 provider 重新注册（`AddonServiceProvider.php:34-39`）。②核心层（`server/app/Providers/` 仅 AddonBootServiceProvider/AppServiceProvider 两个）零 `Event::listen` 注册（全仓 grep 证实），`forget` 不会误杀框架自身的监听。③兄弟插件同事件监听的重挂有测试固化（`AddonListenerDetachTest.php:84-104`：lista 被禁后 listb 计数继续增长）。④disable 前置已把注册行置 enabled=false（180 行），`enabledInfos()` 不会把本插件再挂回来。

caveat（均为契约排除项，暂不构成问题）：①若第三方插件绕过 `$listen` 属性、在 boot 后动态 `Event::listen` 注册，`forget` 会把它连同兄弟插件的动态监听一起杀掉且重挂循环只认 `getDefaultProperties()['listen']`（453、467 行）无法救回——`AddonServiceProvider` docblock 已把「把监听映射写进 $listen」定为契约，属约定外用法；②若未来核心层开始监听某事件且插件也监听同一事件，forget 会连带杀掉核心监听且无人重挂——届时需把重挂来源从「启用插件清单」扩为「启用插件 + 核心」。建议在契约 docblock 里把这两条禁忌写明即可。

---

## 5. hook() 异常语义差异：install（事务内→回滚）vs disable（事务外→半禁用态）

**结论：差异成立；install 侧安全，disable/enable 侧问题与第 2 项同根，合并定级 Minor。**

install 侧反证：`hook($info, 'install')` 在 `DB::transaction` 内（84 行），抛异常 → Addon::create/syncMenus/createPermissions 全部回滚，终态 = 迁移已跑（幂等自愈）+ 注册表干净，重试无障碍——这是全文件防护最好的一处。upgrade 同构（钩子在事务内、version 写回 511 行同事务，失败回滚后磁盘迁移已跑但版本号保持旧值，重试安全，与 478-479 行注释承诺一致）。
disable 侧确认：`hook('disable')`（181 行）在 `update(['enabled' => false])`（180 行）之后且无事务包裹，抛错即半禁用态，且重试被「已是禁用状态」挡住——后果与恢复路径同第 2 项分析，不重复。
另注一个理论边界：install/upgrade 钩子在事务内若产生**非 DB 外部副作用**（写文件、发 HTTP），事务回滚不会撤销它们；Lifecycle 契约对 install 钩子的定位是「种子数据、初始化配置」（`Contracts/Lifecycle.php:9`），现有插件（cms 种子、settings）均为纯 DB 操作，未越界。

---

## 6. purgeFrontend 静默失败

**发现问题 | Minor | 位置 `AddonInstaller.php:304-307`（purgeFrontend）、`:309-322`（deleteDir 全 `@` 吞错）、卸载序列 `:123-126`**
复现路径：`addon:uninstall` 执行到 purgeFrontend 时目录被占用/权限不足 → `deleteDir` 静默半清 → 卸载「成功」返回，注册行/菜单/权限已删（125 行在前），**无任何日志或提示** → `admin/src/addons/<name>` 残留旧产物，被下次前端构建照常编入（同第 3 项 Minor ② 的 glob 分析）；且此时重跑 `addon:uninstall` 报「未安装」，**工具层面没有重试入口**，只能手工 rm。
对比：`syncFrontend` 的每类失败都有 warning 且通过 `lastSyncedFrontend` 如实反馈给命令层，purgeFrontend 是本文件唯一零遥测的文件系统写操作，与模块自身「绝不谎报」的原则不一致。定级 Minor：残留物惰性（无菜单/权限/路由指向它），但零信号 + 无重试入口值得补齐（失败时 logger()->warning 即可）。

---

## 7. database 锁驱动（生产 CACHE_STORE）下 withLock 的行为

**结论：确认安全（生产驱动语义经 vendor 源码核验无隐患），附带 2 条 Minor。**

逐点核验（`server/vendor/laravel/framework/src/Illuminate/Cache/`）：
- **release 语义**：`DatabaseLock::release` 以 `where key = ? and owner = ?` 删除（DatabaseLock.php:138-152）——owner 域限定，即便 TTL 过期后锁被他人抢走，原持有者 finally 里的 `release()` 也是 no-op，不会误删他人锁。withLock 的两道防护（代码注释 40-41 行的「超时不 release」+ 驱动层 owner 校验）互为纵深。
- **锁键竞争**：owner 为每实例随机 `Str::random()`（Lock.php:54-56），`withLock` 每次 `Cache::lock()` 生成新实例新 owner，无串号可能；抢占过期锁的 `acquire` 走单条 UPDATE 带 `owner = me OR expiration <= now` 条件（DatabaseLock.php:81-88），PG 下原子，崩溃持锁可被接管，不会永久死锁。
- **表结构**：`cache_locks` 迁移自框架第一天就存在（`0001_01_01_000001_create_cache_table.php:20-24`），生产 `CACHE_STORE=database`（`config/cache.php:18`、`.env.example:40`）开箱可用。
- **与 DB::transaction 的关系**：锁的 acquire 在事务开启前、release 在 finally（操作事务已闭合），锁语句始终 autocommit，无 savepoint/事务嵌套纠葛。
- 测试盲区评估：array 驱动与 database 驱动共享同一 `Lock` 契约（block 250ms 轮询、owner 语义一致，phpunit.xml:25 固定 array），未测的只是跨进程持久化与墙钟 TTL，均由驱动实现兜底，withLock 自身逻辑无驱动分叉。

**Minor ①** | 位置 `AddonInstaller.php:36-41`：持锁进程崩溃（FPM 被 kill/OOM）后，锁行最长存活 600s，期间所有操作 3 秒内报「正在被另一操作处理，请稍后再试」——信息准确但不提示「最长需等 10 分钟」，运维易误判为故障。
**Minor ②** | 位置 `AddonInstaller.php:36`：单次操作若超过 TTL 600s（如超长迁移），锁过期被他人抢走后互斥尾窗失效，两进程并发操作同一插件。注释已自认「迁移再慢也不应超过它」，理论风险，可在长操作中用 `$lock->refresh()` 加固。

---

## 8. 钩子内回调同插件公开方法导致的锁重入

**结论：无现实触发路径；报错信息对自重入场景有误导性；建议做轻量防护而非可重入锁。定级 Minor。**

- **真实路径排查**：全部四个现有插件的五个钩子均为空实现或纯 DB 幂等操作（`addons/cms/src/Addon.php:13-49`、`demo`/`op_logs` 全空、`settings` 仅卸载时删 Setting 行）；全仓 grep 无任何钩子/插件代码调用 `AddonInstaller` 或 `addon:*` 命令。现有代码零触发。
- **若第三方触发，实际行为**：钩子内调 `install('self')`/`enable('self')` 等 → `withLock` 新建实例随机 owner，`block(3)` 轮询 12 次抢不到（同进程不同 owner 不算重入，Lock.php:54-56 + DatabaseLock.php:81-88）→ 抛 `AddonException("插件 [X] 正在被另一操作处理，请稍后再试")`。发生在 install 钩子（事务内）时**连带回滚整个安装事务**且迁移已跑（可重试，但钩子若确定性回调则每次都炸）；发生在 disable 钩子（事务外）时叠加第 2 项的半禁用态。
- **报错信息评估**：对「被另一进程操作」场景准确，但对自重入场景是**误导**——明明没有任何「另一操作」，提示「稍后再试」重试也永远失败，且不指明根源是「钩子内禁止调用生命周期入口」。运维侧无从下手。
- **防护建议**：不值得做跨驱动可重入锁（owner 语义随驱动各异，复杂度高）；性价比最高的是 ①`Contracts/Lifecycle` docblock 明确写入「钩子内禁止调用 AddonInstaller 任何公开方法 / addon:* 命令」；②可选在 withLock 内维护 per-process 的 `in-hook` 标记，命中时抛「插件 [X] 的生命周期钩子内再次调用了生命周期操作（锁重入）」，把 3 秒等待 + 误导信息变成即时明确报错。

---

## 9. refreshMenusAndPermissions 的互斥测试覆盖

**发现问题 | Minor（测试缺口） | 位置 `server/tests/Feature/Addon/AddonLockTest.php:18-56`**
现状核实：锁测试共 3 条，覆盖对象全是 `install`——locka 验证持锁拒绝与释放后恢复（18-34 行）、lockb 验证业务失败后 finally 释放（36-47 行）、lockc/lockd 验证跨插件不互斥（49-56 行）。**六个加锁入口中其余五个（uninstall/enable/disable/upgrade/refreshMenusAndPermissions）没有任何「预持锁→调用→断言 AddonException」的测试**；`refreshMenusAndPermissions` 仅有经 `ark:crud` 的功能性覆盖（`CrudGeneratorTest.php`、`CrudEndToEndTest.php`），不含并发维度。
缺测风险评估：六入口的 `withLock` 接线各自独立一行（53、97、133、162、197、483 行），共享同一 `withLock` 实现且实现已被 install 用例覆盖，故机制本身风险低；但若未来重构不小心把某一入口改成直调 `do*`（或新增第七入口忘加锁），现有套件**全绿通过**，互斥回归只能靠生产事故发现。建议把锁用例参数化：遍历六个公开方法，各持对应锁键断言抛「另一操作」，一次覆盖全部接线。

---

## 总结

| 级别 | 数量 | 明细 |
| --- | --- | --- |
| Critical | 0 | — |
| Important | 0 | — |
| Minor | 11 | ①磁盘被删插件的禁用/卸载双重死锁；②跨插件菜单名 TOCTOU 换主；③uninstall 钩子双执行/卡死；④enable/disable 钩子失败后无直接重试通道（第 2、5 项同根合并）；⑤syncFrontend 硬杀窗口与 .bak 残留；⑥残留 .tmp/.bak 被前端构建吞入；⑦purgeFrontend 静默失败且无重试入口；⑧崩溃后 10 分钟误导性报错；⑨超 600s 操作的互斥尾窗；⑩锁重入报错误导 + 契约缺失；⑪五入口互斥无测试 |

总体判断：M8 的锁改造与既有防护（install 事务、syncFrontend 回滚、deletePermissions 删除顺序、锁 owner 双重防护）质量扎实，未发现 Critical/Important 级问题；11 条 Minor 集中在「崩溃窗口残留」「零遥测」「无重试通道」「测试缺口」四类边界，均可低成本收口，优先建议处理 ⑦（purgeFrontend 补 warning）与 ⑪（六入口参数化锁测试）。
