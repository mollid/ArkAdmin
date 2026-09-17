# 组C 审计发现 — 同步与命令

审计范围：`server/app/Support/Setup/FrameworkSync.php`、`server/app/Support/Addon/FrameworkCache.php`、`server/database/seeders/DatabaseSeeder.php`、`server/app/Support/Addon/Console/` 全部 9 个命令、`server/app/Support/Crud/Console/ArkCrudCommand.php`（注：FrameworkSync 实际位于 `Support/Setup/` 而非任务描述所写的 Addon 目录）。逐项核对全部 6 条检查单；只读命令已实跑验证（addon:list、非法名、不存在的插件名、upgrade 互斥、upgrade --all 全最新态、ark:sync 两模式），状态变更类命令仅代码推演。

---

## C1 参数边界

**实跑验证（全部安全）**：
- `addon:install definitely_missing` → `插件 [definitely_missing] 不存在或清单无效：插件目录缺少 info.json：…` EXIT=1（AddonInstaller.php:324-336 mustExistOnDisk）
- `addon:install ../demo`、`addon:install BadName` → `非法插件名 [xxx]` EXIT=1（AddonInstaller.php:328-330 正则 `^[a-z][a-z0-9_]*$` 卡在目录定位之前，`../` 越界与大小写均被拒）
- `addon:upgrade nonexistent` / `addon:enable nonexistent` / `addon:uninstall nonexistent` → `插件 [nonexistent] 未安装` EXIT=1
- `addon:upgrade demo --all` → `不能同时指定插件名与 --all` EXIT=1（AddonUpgradeCommand.php:20-24 显式互斥并报错，**非静默偏向其一**）
- `ark:sync --no-addons` → 只同步权限菜单、无插件行、EXIT=0；默认路径 → 两个系统插件以「跳过：已安装」呈现、EXIT=0（实跑输出确认）
- 空扫描集（addons/ 为空）代码推演：`addon:list` 打印空表头 EXIT=0；`addon:upgrade --all` → targets 为空集 → 「没有需要升级的插件」EXIT=0；`addon:cache` 写 `var_export([], true)` 生成合法空映射；`ark:sync` 对 settings/op_logs 逐个输出跳过原因 EXIT=0。均无致命路径。

问题 | Minor | 位置 server/app/Support/Addon/Console/AddonUpgradeCommand.php:27 | 复现路径：addons 注册表缺失（手工回滚等异常态）时执行 `php artisan addon:upgrade --all` → `Addon::query()->pluck('name')` 抛 QueryException，未捕获直接崩栈退出。同族命令 `addon:list` 对同一异常态特意降级为 warn + 只列磁盘（AddonListCommand.php:19-25，注释「不致命」），两处鲁棒性不一致。此态下注册表为空即无可升级对象，降级为「没有需要升级的插件」更一致。理论风险（需注册表异常态），故 Minor。

---

## C2 批量操作的失败聚合与退出码

问题 | Important | 位置 server/app/Support/Addon/Console/AddonUpgradeCommand.php:41-47 | 复现路径：准备两个待升级插件（磁盘版本 > 注册表版本），其中 A 的新迁移含 SQL 错误，执行 `php artisan addon:upgrade --all` → A 的 `Artisan::call('migrate')`（AddonInstaller.php:504）抛 QueryException；循环只捕 `AddonException`，原生异常逃出 foreach，**B 及其后所有目标不再处理**，异常直达控制台内核。与 :42 注释「批量升级不因单个坏插件中止整批，最后统一报失败」直接矛盾——该承诺只对 AddonException 成立。插件 `upgrade()` 钩子抛非 AddonException（AddonInstaller.php:510）同样中止整批。已实证传播链：vendor `Illuminate/Console/Application.php:76` `setCatchExceptions(false)`，`call()` 内的命令异常原样上抛（AddonController.php:66 注释「迁移 SQL / 插件钩子 … 原生异常也要收进信封」也反证了这条传播链真实存在，HTTP 侧已捕 `\Throwable`，CLI 侧没有）。两点缓释：退出码仍非零（内核对未捕获异常返回 1），且错误信息带完整堆栈可定位。修复方向二选一：循环内改捕 `\Throwable`（记 error + failed++，与注释对齐），或修正注释为「原生异常响亮失败、整批中止」（与 FrameworkSync 的设计决策对齐）。附带：注释称「最后统一报失败」但实现里并无汇总行（只有逐项 error + 退出码），修注释时一并措辞。

**确认安全**：AddonException 类单插件失败不中止整批（:46 `continue`）、失败与成功混合时 EXIT=1（:62 `return $failed === 0 ? self::SUCCESS : self::FAILURE`）、每个失败插件逐项打印可定位消息——代码与实跑（全部「已是最新」路径）一致。

---

## C3 `--force` 语义一致性

**确认安全**：两个命令的 `--force` 均与帮助文本/AGENTS.md 一致——`addon:upgrade --force` 帮助「跳过版本比较（版本未 bump 但需补跑迁移）」，实现即跳过 `version_compare(..., '<=')` 的空转短路（AddonInstaller.php:493）；`ark:crud --force` 帮助「覆盖已生成文件（标记对内容整块替换）」，实现为跳过 7 个目标文件的存在性守卫（CrudGenerator.php:46-52），标记对区域无论 force 与否都幂等整块替换（CrudGenerator.php:276-278、302-304）。两者同属「绕过幂等守卫、重做」语义族，互不冲突。

问题 | Minor | 位置 server/app/Support/Addon/AddonInstaller.php:493,511 | 复现路径：插件注册表版本 1.1.0、磁盘清单被回退到 1.0.0，执行 `addon:upgrade 该插件 --force` → force 不区分「等版本补跑」与「磁盘版本低于注册表」，放行后 `migrate` 不会回滚高版本已跑迁移，随后 :511 把注册表 version **写回更低值**，注册表版本与实际 DB schema 永久漂移。需要版本回退才触发，理论风险；可在 force 分支加 `info->version >= record->version` 门槛或至少打告警。

问题 | Minor | 位置 server/app/Support/Addon/AddonInstaller.php:510 | 复现路径：版本忘了 bump 时按 AGENTS.md 执行 `addon:upgrade x --force` 补跑——文档与帮助只说「补跑迁移」，实际 `upgrade($from)` 钩子也会**再次完整执行**；upgrade 钩子若非幂等（如再插一条种子数据）会被双跑。属文档/帮助文本对 --force 副作用的欠说明，建议在帮助文本补一句「并重跑 upgrade 钩子」。

---

## C4 ArkSyncCommand 输出与 FrameworkSync 返回结构的对应

**确认安全**：`FrameworkSync::run()` 返回仅 `installed`（list）与 `skipped`（map）两键，ArkSyncCommand.php:19-24 与 DatabaseSeeder.php:14-19 均完整输出两个键的全部条目，**无返回后被静默丢弃的信息**。`$cache->rebuild()` 的失败按注释固化设计降级为日志告警（FrameworkCache.php:21-23），不进控制台输出，属已知设计决策不计问题。附带观察（不构成缺陷）：稳态输出「系统插件 [settings] 跳过：插件 [settings] 已安装」——已安装是正常态却以「跳过」呈现，语义真实（原样透传 AddonException 消息），仅初见者可能误读。

---

## C5 命令输出文案与实际行为的漂移

问题 | Minor | 位置 server/app/Support/Addon/Console/AddonEnableCommand.php:25-27 | 复现路径：禁用态插件源码前端有更新后执行 `addon:enable x` → enable 流程会 syncFrontend 重拷前端产物（AddonInstaller.php:157，§9 验收 5 自愈），生产环境此后需要 `npm run build`，但 enable 成功文案只有「已启用」，无构建提示。install（AddonInstallCommand.php:30）、upgrade（AddonUpgradeCommand.php:55）、ark:crud（ArkCrudCommand.php:57）都有同款提示，enable 是唯一做了前端同步却不提示的，属文案/行为漂移。

问题 | Minor | 位置 server/app/Support/Addon/AddonManager.php:164,232（命令出口 AddonCacheCommand.php:17、AddonClearCommand.php:17） | 复现路径：bootstrap/cache 不可写或缓存文件被外部占用时执行 `addon:cache` / `addon:clear` → `compile()` 的 `file_put_contents` 返回值未检查、`flushCompiled()` 用 `@unlink` 静默吞错，两命令仍输出「插件缓存已生成/已清除」并 EXIT=0（假成功）。链路后果：`addon:disable` 后 flush 失败残留旧编译缓存时，`loadableInfos()` 只信缓存快照、不复核 enabled（AddonManager.php:112-125），已禁用插件会被继续加载。触发前提异常（Laravel 本身也要求 bootstrap/cache 可写），故 Minor；但「不谎报成功」原则在 syncFrontend 一侧执行得很严格（AddonInstaller.php:225-281），此处应对齐（至少 `file_put_contents === false` 时 error + FAILURE）。

问题 | Minor | 位置 server/app/Support/Addon/Console/AddonUpgradeCommand.php:32-34 | 复现路径：`addon:upgrade x`，x 已注册但磁盘目录被删 → :32-34 先打印「插件 [x] 磁盘缺失，跳过（请手工清理注册表后重装）」，随后 x 作为显式目标进入 upgrade 并抛「插件 [x] 不存在或清单无效」→ EXIT=1。该消息本为批量语境的逐项告知，单名场景下「跳过」与实际「失败」矛盾且两条消息重复。建议 :32 循环在单名模式排除显式目标，或改措辞为「磁盘缺失」中性问题。

**确认安全**：逐命令核对通过——`addon:install` 的「迁移已执行、菜单与权限已写入、插件已启用」三要素与 doInstall（AddonInstaller.php:56-92）逐一对应；前端提示三分支（已同步 / 有前端但同步失败 / 无前端静默）与 syncFrontend 的 null 语义及 `lastSyncedFrontend` 复位时机（:227）严格对应，失败不谎报；`addon:uninstall` 的 keep-data 两态文案与 doUninstall 行为一致；「已是最新（如需补跑迁移请加 --force）」的判定条件（版本 `<=` 且未 force，AddonInstaller.php:493）实跑验证吻合；「没有需要升级的插件」仅在 upgraded=0 且 failed=0 时打印（AddonUpgradeCommand.php:58-60），不会掩盖失败。

---

## C6 系统插件（settings/op_logs）的 CLI 侧约束

**确认安全（现状即设计，非缺陷）**：CLI 侧 10 个命令逐一精读，对 system_addons **无任何禁停约束**；HTTP 侧 `update()` 拦 disable（AddonController.php:88-90）、`destroy()` 拦 uninstall（:114-116），报错文案「请使用 CLI 操作」把 CLI 定位为运维逃生门，AGENTS.md 亦明文「CLI 不受限」。评估：有意设计，CLI 不受 GUI 约束是常见且合理的运维语义，且 CLI 侧卸载本身有「先 disable 再 uninstall」两步门槛（AddonInstaller.php:106-108），误伤需要连续两次明确动作。附带确认一处不对称（记录性质，不建议改）：`addon:uninstall settings` 后跑 `ark:sync` 会自动重装（注册表无记录 → install 成功）；而 `addon:disable settings` 后 `ark:sync` 走 install → 「已安装」异常 → skipped（AddonInstaller.php:59-61 只查存在不查 enabled），**不会自动重新启用**——这尊重运维手动禁用的意图，但意味着误禁用后设置页/日志要坏到手工 `addon:enable` 为止，与「随 db:seed 幂等自动安装」的直觉有落差，文档已算覆盖（HTTP 报错明示了 CLI 代价）。

---

## 总结

| 级别 | 数量 |
|------|------|
| Critical | 0 |
| Important | 1 |
| Minor | 6 |

**Important 1 条**：C2 `addon:upgrade --all` 对原生异常（迁移 SQL/插件钩子）会中止整批，与 :42 注释承诺矛盾（AddonUpgradeCommand.php:41-47）。
**Minor 6 条**：C1 注册表缺失态下 `upgrade --all` 崩溃与 `addon:list` 降级行为不一致；C3 `--force` 允许磁盘版本回退时向下写回注册表版本；C3 `--force` 重跑 upgrade 钩子未在帮助/文档说明；C5 `addon:enable` 缺前端构建提示；C5 `addon:cache/clear` 写缓存失败仍报成功（假成功）；C5 单名 upgrade 指向磁盘缺失插件时「跳过」文案与实际失败矛盾。
核心同步链（FrameworkSync 返回结构消费、FrameworkCache 降级、C1 参数边界、C6 系统插件约束）经实跑与代码双重验证，均安全。
