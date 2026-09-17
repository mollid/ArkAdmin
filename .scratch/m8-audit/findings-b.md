# 组B 审计发现 — 清单/扫描/依赖

审计对象：`server/app/Support/Addon/AddonInfo.php`、`AddonManager.php`、`AddonDependency.php`，并追读 `AddonInstaller.php`、`AddonServiceProvider.php`、`AddonBootServiceProvider.php`、`Console/*`、`Models/Addon.php`、`create_addons_table` 迁移。运行时语义（glob 对目录、截断 PHP require、空文件 require、foreach 非数组）已在容器内 PHP 8.3.33 用 /tmp 隔离实验逐一验证，未改动任何仓库文件。

---

## B1 畸形清单边界

### B1a 发现：清单字符串字段无长度上限，超长值漏到 DB 层才炸（裸 SQLSTATE）| Minor | AddonInfo.php:34-38 + server/database/migrations/2026_09_15_000001_create_addons_table.php:13-14
复现路径：制作 info.json，`title` 填 300+ 字符（或合法 `x.y.z-` 前缀后 prerelease 拖到 32 字符以上，正则 `AddonInfo.php:51` 不限长）→ `addon:install` 通过全部清单校验（必填/正则/目录一致）→ `AddonInstaller::doInstall`（AddonInstaller.php:73-79）在 `DB::transaction` 内 `Addon::create(['title' => ...])` 命中 PostgreSQL `varchar(255)`/`varchar(32)` 上限抛 SQLSTATE 22001。事务回滚无数据损坏（迁移在事务外但幂等），但 CLI/HTTP 用户看到的是裸 SQL 错误而非「清单字段超长」，违反「无效清单不进入安装流程」的报错口径；`addon:upgrade` 写回 `title`/`version`（AddonInstaller.php:511）同理在升级末段炸。`description` 无上限且不落库（`Addon::create` 不含 description），仅在 AddonController.php:145 原样进列表接口——几 MB 的 description 会被接受并撑大 HTTP 响应/CLI 输出（前提是攻击者已有文件系统写权限，故仅 Minor）。

### B1b 发现：卸载期自依赖死锁（依赖事后台改清单）| Minor | AddonDependency.php:47-61 + AddonInstaller.php:111-114
复现路径：安装插件 X（清单无自依赖）→ 手工编辑 `addons/X/info.json` 给 `dependencies` 加 `"X"`（fromDir 不查 `name ∈ dependencies`，AddonInfo.php:63-72 放行）→ `addon:uninstall X`：`dependents(X, false)` 遍历注册表含 X 自己，`in_array('X', ['X'])` 命中 → 抛「正被已安装插件 [X] 依赖，请先卸载它们」→ 先卸载依赖方的指令指向 X 自身，死循环，只能手工改回清单。前置卡口反而都在：install 被挡（`Addon::find(self)` 为 null，AddonDependency.php:21-25）；upgrade/enable 被 `assertAcyclic` 挡（AddonDependency.php:71-72 抛「依赖成环」）——唯独 uninstall 没有「依赖方 ≠ 自己」的过滤。因需带外篡改已装清单，定 Minor。

### B1c 确认安全：type 大小写异常 → 严格拒绝，不规范化
`AddonInfo.php:57` 用 `!== 'app'` 严格比较，`App`/`APP`/`app `（尾空格）全部报「暂不支持的插件类型 [App]」并跳过/拒装。行为确定性拒收而非静默规范化，符合「校验失败不进入流程」契约。反证：该分支必抛 AddonException，scan/install/upgrade 全部消费方均捕获或直接失败，无静默放行路径。

---

## B2 scan() 对半损坏目录

确认安全，四类半损坏全部「跳过 + warning 落日志」，不崩溃、不产脏数据：

- **缺 src/**：AddonInfo.php:60-62 抛「插件缺少 src 目录」→ scan 捕获（AddonManager.php:42-48）。
- **info.json 语法错误**：`json_decode` 得 null，`is_array` 不过（AddonInfo.php:30-33）→ 抛 → 跳过。
- **info.json 是目录**：实验验证 glob（PHP 8.3.33）**确实会匹配名为 info.json 的目录**，但 `AddonInfo.php:27` 的 `is_file()` 对目录返回 false → 抛「缺少 info.json」→ 跳过。目录形态不会穿透到 file_get_contents。
- **info.json 不可读（chmod 000）**：`file_get_contents` 返回 false，`(string) false === ''`，`json_decode('')` 为 null → 同上抛出。`(string)` 强转恰好救了这一路径。

不产脏数据的反证：`name === basename($dir)` 强制（AddonInfo.php:43-48）使 scan 的 key 与目录一一对应且天然唯一；glob 返回 false 时 `?: []` 兜底（AddonManager.php:41）；scan 只捕 AddonException，但上述路径只可能抛 AddonException（is_file/file_get_contents/glob 均不抛异常），不存在漏网 Throwable。

---

## B3 依赖环检测（assertAcyclic）

确认安全：菱形依赖不会误判，复杂度无爆炸。

- **菱形不误判（已推演）**：A→B、A→C、B→D、C→D。D 首次经 C 分支入栈并处理完毕；B 分支再遇 D 时 `isset($visited[$dep])` 剪枝（AddonDependency.php:74），不重复展开，栈空正常返回。关键正确性论证：`visited` 只防「同一节点二次入栈」，任何从 root 可达的节点恰入栈一次，出栈时其**全部出边（含指向 root 的边）必被检查一次**——因此「root→…→u→root」型环必然在检查 u 的出边时命中 `dep === $info->name`（AddonDependency.php:71-72）；throw 仅发生在 path 为真实边路径且 dep===root 时，无误报。已另验环 A→B→C→A、以及「C 分支剪掉 B 后 B 原栈帧仍会检查 B→A」的交错序。
- **不经过 root 的下游环（B→C→B）本函数不抛**：这是刻意口径——B 自己的 install/enable/upgrade 卡口以 root=B 会命中（AddonInstaller.php:67/152/502 三处入口）。唯一豁口是带外篡改两个**已启用**插件的清单造出互环且不再触发任何卡口（运行期 boot 不查依赖），与 B1b 同属带外篡改范畴，不单独计问题。
- **最坏复杂度**：每节点至多入栈一次 → 边检查 O(V+E)；`[...$path, $dep]` 拷贝与 `in_array` 为 O(路径长)，深链最坏 O(V²)。V=插件数（个位/十位量级），无指数风险。
- **dependencies 含不存在的插件**：install/enable 在进入 assertAcyclic 前即被 `Addon::find($dep) === null || ! isset($scan[$dep])` 挡下（AddonDependency.php:21-25、33-38），报「依赖插件 [X] 未安装」；不存在「安装序」自动拓扑——设计为手工先装依赖，报错即指引。dependents 以注册表行为循环主体，不存在的插件不在注册表不参与；已装插件的悬空依赖会使它被保守计为 dependent，uninstall 偏「宁可挡」，方向安全。

---

## B4 dependents() 数据口径

**确认安全（主口径）**，一处 Minor 附带发现。

口径结论：**安装/启用集合来自注册表（DB，AddonDependency.php:51-53），依赖声明来自磁盘 scan（第 49、54-55 行）**——即「DB 定人、磁盘定据」。

- **磁盘有 DB 无**（从未安装 / 已卸载但目录留存）：不在 DB 循环内 → 不算 dependent。正确：未安装插件不因卸载其依赖而被破坏。
- **DB 有磁盘无**（ghost，目录被删的已装插件）：`$scan[$record->name] ?? null` 为 null → 跳过。此局限已在 docblock 声明（AddonDependency.php:44-45）。对 uninstall 前置检查（AddonInstaller.php:111，`enabledOnly=false` 连禁用态依赖方也挡，卸载顺序=先卸依赖方）意味着：ghost 不能阻塞卸载，但 ghost 本身已是异常态（boot 时被跳过，AddonManager.php:69-73；`addon:list`/HTTP 列表均标记 disk_missing），卸载其依赖不产生**新**脏状态。
- **「卸依赖后启用依赖方」漏洞**：uninstall 用 enabledOnly=false 全量挡 + enable 时 `assertEnableable` 复核（AddonDependency.php:33-38）双保险，闭环。
- **Minor 发现：dependents() 每次调用全表查 addons，列表页形成 N+1** | AddonDependency.php:51-53 + AddonController.php:155 | 复现：`AddonController::index` 对每个磁盘插件调 `row()` → 每行一次 `dependents()` → 每次一条 `Addon::query()->...->get()`。docblock 声称「列表页 N 行共用一次 scan」只复用了 scan，DB 查询未复用。表极小（插件数级），性能影响可忽略，属与注释意图的偏差，定 Minor。

---

## B5 flushCompiled 与磁盘/DB 变更的时序窗口

### B5a 发现：compile() 无锁且非原子写，与生命周期并发产生「禁用插件仍在运行」的陈旧缓存 | Important | AddonManager.php:151-165（file_put_contents 在 164）+ AddonCacheCommand.php:14-20
复现路径（生产启用编译缓存场景，`bootstrap/cache/addons.php` 存在）：
1. 终端 A 执行 `php artisan addon:cache` → `compile()` 先 `enabledInfos()` 读 DB（此刻插件 foo 仍 enabled）；
2. 终端 B 执行 `php artisan addon:disable foo` → 落库 `enabled=false` → `finish`/`doDisable` 里 `flushCompiled()`（AddonInstaller.php:183）unlink——**此刻缓存文件尚未被 A 写出，unlink 空操作**；
3. 终端 A 继续 `file_put_contents` 写出**含 foo 的陈旧映射**；
4. 此后每次 boot 走 `loadableInfos()` 缓存分支（AddonManager.php:112-124），foo 的 provider/路由/监听照常注册——「已禁用」在运行态静默失效，直到下一次任何生命周期操作 flush 或手工 `addon:clear`。

反向交错（cache 读在 disable 落库后、写在 flush 前 → 新装/已启用插件被漏出缓存）同理。根因有二：生命周期锁是 **per-addon** 且 `addon:cache` **根本不取任何锁**（AddonInstaller::withLock 只包 install/uninstall/enable/disable/upgrade/refresh，AddonInstaller.php:34-48）；写出无 tmp+rename 原子替换（对比同代码库 `syncFrontend` 对前端产物的 tmp+bak+rename 三步原子替换，AddonInstaller.php:251-278——同一作者已示范过正确做法）。另附带：`file_put_contents` 返回值未检查，磁盘满时命令仍打印「插件缓存已生成」（AddonCacheCommand.php:17），谎报成功。

### B5b 发现：flushCompiled 静默吞失败 + DB 落库与 flush 之间的崩溃窗口，陈旧缓存无任何自检 | Minor | AddonManager.php:230-233 + AddonInstaller.php:180/183、125/127
复现路径一：生产对 `bootstrap/cache` 做只读加固（部署加固常见）→ `addon:disable foo` DB 落库成功 → `@unlink` 失败被 `@` 压制，无告警 → 缓存仍列 foo → foo 持续加载。复现路径二：`doDisable` 在 `$record->update(['enabled' => false])`（AddonInstaller.php:180）与 `flushCompiled()`（183）之间进程被杀（`doUninstall` 的 125→127 同理）→ 同款陈旧缓存。两案的共性是「缓存与注册表的一致性完全依赖 unlink 成功 + 顺序不中断」，无事后校验。窗口极窄/前提是 FS 异常，定 Minor。

**缓解性事实（为何磁盘内容变更本身无害）**：`loadableInfos()` 对每个缓存条目重新 `AddonInfo::fromDir($entry['dir'])` 现读磁盘清单（AddonManager.php:118），插件目录内容变更（部署新版源码）不会被陈旧缓存固化——缓存只固化「启用集合 + title/version 展示元数据」，磁盘清单永远是新鲜的。

---

## B6 编译缓存损坏文件的自愈路径

### B6a 发现：截断/语法错误的 addons.php → 全站 fatal，且**连修复命令本身也 fatal**，无任何自动自愈 | Important | AddonManager.php:116（require 位于 try 外，第 117-121 行只捕 AddonException）
复现路径：磁盘满时 `compile()` 半写 `bootstrap/cache/addons.php`（或 B5a 的并发写撕裂、手工误编辑）→ 下一次任意请求/命令引导时 `loadableInfos()` 的 `require` 抛 **ParseError**（容器实测：PHP 8.3.33 对截断 PHP 文件 require 抛 `ParseError: syntax error`）→ 该异常不是 AddonException，逃过 119 行 catch → 穿透 `boot()`（AddonManager.php:80-101 无兜底）→ `AddonBootServiceProvider::boot()`（AddonBootServiceProvider.php:21）→ **每个 web 请求 500、每条 artisan 命令引导期炸**——包括本应用于修复的 `php artisan addon:clear`（artisan 同样全量 boot providers，ParseError 在 BootProviders 阶段抛出，命令体根本不执行）。唯一恢复路径是登录服务器手工 `rm bootstrap/cache/addons.php`。不判 Critical 的理由：无数据损坏/安全问题，且前提是异常 FS 事件；但「自我修复命令也被锁死」值得按 Important 处理。修复方向（供排期参考，本次未改码）：compile 改 tmp+rename 原子写（同 B5a）；`loadableInfos` 对 require 包 `\Throwable`，失败时 `flushCompiled()` 并降级走 `enabledInfos()` 既有回退路径（112-113 行已存在）。

### B6b 确认半安全子案：缓存是合法 PHP 但形状错误 → 静默零插件，无 fatal
复现路径：`addons.php` 内容被改成 `<?php return null;` 或被截成 0 字节（`file_put_contents` 失败后残留空文件）→ `require` 正常返回（实验实测：空文件 require 返回 `int(1)`）→ `foreach (require ... )` 仅发 E_WARNING 循环跳过（PHP 8.3 实测：`foreach() argument must be of type array|object`，不抛异常）→ `$infos` 为空，而 `isCompiled()` 为真（AddonManager.php:112）不走 DB 回退 → **全部插件静默不加载**（路由/菜单消失但页面存活）。比 B6a 温和（可诊断），但同样无自愈与告警，并入 Minor 计。

---

## 总结

| 级别 | 数量 | 明细 |
| --- | --- | --- |
| Critical | 0 | — |
| Important | 2 | B5a（compile 无锁非原子写 → 禁用插件静默续跑/启用插件漏载）、B6a（损坏缓存全站 fatal 且 addon:clear 自身不可用，无自愈） |
| Minor | 5 | B1a（清单字段无长度上限 → DB 层裸 SQLSTATE）、B1b（事后台改清单 → uninstall 自依赖死锁）、B4（dependents 每行全表查询 N+1）、B5b（@unlink 吞失败 + 落库与 flush 间崩溃窗口）、B6b（合法 PHP 但形状错误 → 静默零插件） |

确认安全项：B1c（type 严格拒绝）、B2 全项（四类半损坏目录均跳过+告警）、B3 全项（菱形不误判、复杂度无爆炸、缺失依赖在安装/启用卡口拦截）、B4 主口径（DB 定人/磁盘定据，ghost 局限已文档化且方向保守）。

修复优先级建议：B6a 与 B5a 同根（compile 写出路径），一并修（tmp+rename + require 容错降级）收益最大；B1a/B1b 可在 AddonInfo 加一条长度校验 + dependents 过滤 `!== $name` 顺手收口。
