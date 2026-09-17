# M8 全链审计 · 分级总表

> 产生：Task 5 四组并行 fresh-eyes 审计（2026-09-17），基线 main@8e4d8fc，后端 219 passed / 952 assertions，前端 30 passed。
> 明细：[findings-a.md](findings-a.md)（安装器与生命周期）· [findings-b.md](findings-b.md)（清单/扫描/依赖）· [findings-c.md](findings-c.md)（同步与命令）· [findings-d.md](findings-d.md)（HTTP/前端/生成器）。
> 统计：**Critical 0 · Important 4 · Minor 27**（B4≡D1 为同一发现，已合并）。
> 核实口径：每条标 **真实缺陷** / **误报**（记反证）/ **刻意行为**（记理由）。核实结果由用户逐项确认后填入。

## Important（4）

| id | 问题 | 位置 | 复现要点 | 核实 |
|---|---|---|---|---|
| B5a | `compile()` 无锁且非原子写（对比 syncFrontend 的 tmp+rename 规范做法）：`addon:cache` 读 DB 后、写盘前若发生 disable/uninstall → flushCompiled 空操作 → 陈旧映射照常落盘，已禁用插件持续注册 provider/路由，直到下次 flush | AddonManager.php:151-165 | 终端 A `addon:cache` 与终端 B `addon:disable foo` 交错（写盘晚于 flush） | 待核实 |
| B6a | 编译缓存文件截断/语法错误 → `loadableInfos()` 的 `require` 抛 ParseError（只捕 AddonException 拦不住）→ 全站 500 且 `addon:clear` 自身引导期即炸，唯一恢复是手工删文件 | AddonManager.php:116 | `bootstrap/cache/addons.php` 半写后任意请求/命令 | 待核实 |
| C2 | `addon:upgrade --all` 循环只捕 AddonException：迁移 SQL 或插件钩子抛原生异常 → 整批中止，与 ：42 注释「不中止整批」矛盾（HTTP 侧已捕 \Throwable，CLI 侧没有） | AddonUpgradeCommand.php:41-47 | A 迁移含坏 SQL + B 待升级 → A 炸后 B 不处理 | 待核实 |
| D5 | `writeMarkerRegion` 追加类目标文件缺失时把裸块当合法文件落盘：文档合法的「只有 menus.php 标记」插件经 ark:crud 生成无 `<?php`/无 return 的 permissions.php → 后续 install/upgrade 被「必须返回字符串数组」卡死且报错误导 | CrudGenerator.php:269-288 | 插件缺 database/permissions.php（可选文件）→ 跑 ark:crud | 待核实 |

## Minor（27）

### 组A 生命周期（11）

| id | 问题 | 位置 | 核实 |
|---|---|---|---|
| A1 | 磁盘目录被运维直删的已装插件：disable 被mustExistOnDisk 拒、uninstall 要求先 disable → 双重死锁，注册行永久残留 | AddonInstaller.php:109,174 | 待核实 |
| A2 | 跨插件菜单名占用检查 TOCTOU：两插件并发安装同名菜单，updateOrCreate 换主 addon_key | AddonInstaller.php:354-360 | 待核实 |
| A3 | uninstall 钩子抛错后重试会双执行；确定性抛错则插件永远卸不掉 | AddonInstaller.php:122-125 | 待核实 |
| A4 | enable/disable 钩子失败 → update 已提交、重试被「已是目标状态」挡，只能反向舞步恢复（§2+§5 同根合并） | AddonInstaller.php:153-154,180-181 | 待核实 |
| A5 | syncFrontend 硬杀窗口：两次 rename 之间 dest 短暂缺失 / `.bak-<pid>` 残留（异常路径完备，仅 SIGKILL 面） | AddonInstaller.php:263-278 | 待核实 |
| A6 | 残留 `.tmp/.bak` 目录被前端三个 import.meta.glob 编入构建产物（死 chunk + 垃圾 i18n 命名空间） | deleteDir @ 静默 + 前端 glob | 待核实 |
| A7 | purgeFrontend 静默失败零遥测，卸载后无重试入口（「未安装」挡回），只能手工 rm | AddonInstaller.php:304-322 | 待核实 |
| A8 | 崩溃持锁后 600s 内报「另一操作」但不提示最长等待 10 分钟，运维易误判 | AddonInstaller.php:36-41 | 待核实 |
| A9 | 单次操作超 TTL 600s 锁过期被抢，互斥尾窗失效（理论风险） | AddonInstaller.php:36 | 待核实 |
| A10 | 钩子内回调生命周期入口 → 锁重入 block(3) 超时，报错误导（明明无「另一操作」）；契约 docblock 未写禁忌 | withLock + Contracts/Lifecycle | 待核实 |
| A11 | 六入口锁互斥仅 install 有测试；其余五入口接线无「预持锁→断言拒绝」覆盖，重构失锁全绿通过 | AddonLockTest.php:18-56 | 待核实 |

### 组B 清单/扫描/依赖（4，另 B4≡D1）

| id | 问题 | 位置 | 核实 |
|---|---|---|---|
| B1a | 清单字符串字段无长度上限 → 超长 title 落到 DB 层抛裸 SQLSTATE 22001（事务回滚无损坏，但违反「无效清单不进安装流程」报错口径） | AddonInfo.php:34-38 | 待核实 |
| B1b | 事后台改清单加自依赖 → uninstall 抛「正被 [X] 依赖」指向自身死循环（install/enable/upgrade 均有卡口，唯 uninstall 漏） | AddonDependency.php:47-61 | 待核实 |
| B5b | `@unlink` 吞失败 + DB 落库与 flushCompiled 间崩溃窗口 → 陈旧缓存无自检（与 B5a 同域的窄窗口面） | AddonManager.php:230-233 | 待核实 |
| B6b | 缓存为合法 PHP 但形状错误（`return null;`/0 字节）→ foreach E_WARNING 静默零插件加载，无自愈无告警 | AddonManager.php:112-124 | 待核实 |

### 组C 同步与命令（6）

| id | 问题 | 位置 | 核实 |
|---|---|---|---|
| C1 | 注册表缺失态下 `addon:upgrade --all` 崩栈，而 `addon:list` 同态降级 warn——鲁棒性不一致 | AddonUpgradeCommand.php:27 | 待核实 |
| C3a | `--force` 允许磁盘版本回退时把注册表 version 向下写回，与实际 schema 永久漂移 | AddonInstaller.php:493,511 | 待核实 |
| C3b | `--force` 补跑迁移同时重跑 upgrade 钩子，帮助文本未说明（非幂等钩子会被双跑） | AddonInstaller.php:510 | 待核实 |
| C5a | `addon:enable` 做了前端同步却缺「需 npm run build」提示（install/upgrade/crud 都有） | AddonEnableCommand.php:25-27 | 待核实 |
| C5b | `addon:cache/clear` 写失败（只读目录/占用）仍报成功 EXIT=0，假成功（与 B5a/B5b 同根） | AddonCacheCommand.php:17 等 | 待核实 |
| C5c | 单名 upgrade 指向磁盘缺失插件时先打印「跳过」再失败，两条消息矛盾重复 | AddonUpgradeCommand.php:32-34 | 待核实 |

### 组D HTTP/前端/生成器（6，含 D1≡B4）

| id | 问题 | 位置 | 核实 |
|---|---|---|---|
| D1 | `index()` 每行调 `dependents()` → N 次注册表全表查询 N+1，与「避免逐行 N+1」注释矛盾（≡组B B4） | AddonController.php:39,155 | 待核实 |
| D4a | 升级空跑（并发窗口）前端无条件提示「升级成功」，与后端「已是最新版本」漂移 | index.vue:96 | 待核实 |
| D4b | 卸载弹窗正文恒写「将回滚业务表」，「保留数据」分支下不准确 | index.vue:110 | 待核实 |
| D5b | 同插件 snake 归一撞车（cms_article/cms_articles→Article）+ `--force` 静默覆盖前表模块与菜单 | CrudGenerator.php:93-114 | 待核实 |
| D6a | ark:crud `file_put_contents`/`@mkdir` 返回值未检查，失败仍报「已生成」 | CrudGenerator.php:56-60 | 待核实 |
| D6b | 生成控制器 findOrFail 的 404 信封透出英文异常原文（全站统一行为，非生成器独有） | controller.stub:37,55 | 待核实 |

## 确认安全项（反证摘要，详见各组明细）

- **A**：install 事务边界（迁移幂等自愈+事务回滚干净）、uninstall 三连删幂等可重试、syncFrontend 异常路径回滚完备、detachListeners 单线程窗口内无并发注册（契约排除动态注册）、database 锁驱动 owner 语义核验安全、锁重入现有插件零触发路径。
- **B**：type 大小写严格拒绝不规范化、scan 四类半损坏目录全部跳过+告警不产脏数据、菱形依赖不误判为环（visited 剪枝论证完整）、dependents「DB 定人磁盘定据」主口径方向保守。
- **C**：参数边界（非法名/不存在/互斥/空集）实跑全过、FrameworkSync 返回结构无静默丢弃、`--force` 两命令语义族一致、系统插件 CLI 不受限属设计（运维逃生门）。
- **D**：信封契约逐端点过关（无裸 500 面）、keep_data 全链语义一致且方向保守、AddonRow 前后端逐字段零漂移、三选卸载语义对齐、标记对幂等机制无嵌套吞噬、stubs 无危险 DDL、`--force` 覆盖语义已声明。
