# ArkAdmin M8 正确性打磨 — 验收记录

> 日期：2026-09-20 ｜ 计划：`2026-09-17-arkadmin-m8-correctness.md` ｜ 规格：`specs/2026-09-17-arkadmin-harness.md` §5 ｜ 状态：**完成**

## 里程碑完成标志

四条线全部落地：

1. **生命周期并发互斥（锁）**：`AddonInstaller::withLock()` 包裹全部六个注册表写入口（install/uninstall/enable/disable/upgrade/refreshMenusAndPermissions），原方法体平移为 `do*` 受保护方法，公开签名零变化；锁键 `arkadmin:addon:{name}`，TTL 600s 防死锁，`block(3)` 快速失败进 AddonException 信封（「插件 [X] 正在被另一操作处理，请稍后再试」）。六入口锁接线全量参数化测试（AUDIT-A11）。
2. **FrameworkCache 路径注入直测**：构造器注入 cachePath（默认 `base_path('bootstrap/cache')`），直测指向临时目录 + Artisan mock，测试环境零污染。
3. **info.json 版本格式校验**：版本号格式收口在 `AddonInfo::fromDir`（`x.y.z[-prerelease]`，所有生命周期入口必经）；畸形版本（`v0.2.0`）在 install/upgrade 前即被「版本号格式无效」拒绝，不再漏进 DB 层。
4. **全链审计 + 修复轮**：四组并行 fresh-eyes 审计（安装器与生命周期 / 清单·扫描·依赖 / 同步与命令 / HTTP·前端·生成器）产出 31 条分级发现，用户逐项核实（0 误报）后 28 条真实缺陷进入修复轮，**21 条已修**（4 Important + 17 Minor，全部配 AUDIT-* 命名回归测试），7 条 Minor 有意遗留（见「待办与遗留」）。

## 自动化测试

- 后端 Pest：**241 passed / 1032 assertions**（审计基线 219/952 → 修复轮 +22 条：编译缓存原子写/自愈 5、upgrade 命令治理 3、CRUD 生成器防护 4、锁互斥参数化 1、清单校验 1、自依赖 1、前端同步残留告警 1、构建提示 2、N+1 1、撞车/落盘失败 2 等）
- 前端 Vitest：**30 passed**、`vue-tsc -b` 通过、`npm run build` 成功

## 开发库实测（HTTP/CLI 真实链路）

1. **并发锁**：容器内持锁进程（tinker 持 `arkadmin:addon:demo` 6s）期间发 HTTP `PUT /addons/demo disable` → **3.6s 快速失败**，code 1「插件 [demo] 正在被另一操作处理，请稍后再试」，插件状态无变化；锁释放后 disable/enable 立即恢复正常（无卡死）。补充观察：纯 HTTP 并发双写（disable/enable 同时到达）由锁外状态机预检查拦截（预检查窗口极小，先完成者改态、后者得「已是目标状态」），终态合法——锁竞争路径由持锁实测与 AUDIT-A11 测试覆盖。
2. **ark:sync 幂等**：连续两次执行输出一致，权限/菜单同步、系统插件「已安装」跳过 ✓
3. **addon:upgrade --all**：四插件（cms/demo/settings/op_logs）全部「已是最新（如需补跑迁移请加 --force）」✓
4. **畸形版本防护**：临时改 `addons/demo/info.json` version 为 `v0.2.0` → `addon:upgrade demo` 报「info.json 字段 version 版本号格式无效 [v0.2.0]（须为 x.y.z[-prerelease]）」EXIT=1 → 已还原 ✓

## 审计分级统计

- 产出 **31 条**（明细见 `.scratch/m8-audit/findings.md` 与 findings-a/b/c/d.md）：**Critical 0 · Important 4（B5a/B6a/C2/D5）· Minor 27**（B4≡D1 同一发现已合并）
- 核实（Task 5 Step 3，2026-09-20 逐条对照源码）：**技术全部属实，0 误报** → 28 条真实缺陷 + 3 条刻意行为
- 刻意行为裁决 3 条：**A5**（syncFrontend SIGKILL 硬杀窗口——微秒级、自愈路径完备，已按裁决在 docblock 注记）、**A9**（600s TTL 互斥尾窗——与崩溃等待时长权衡的合理折中）、**D6b**（404 信封英文原文透传——全站统一行为，归 M9 全局修）
- 修复轮：**21 条已修**（Important 4 + Minor 17，每条配 `AUDIT-*` 回归测试）；**7 条 Minor 遗留**（见下）

## 修复轮明细（21 条）

| 主题 | 条目 | 修法一句话 |
|---|---|---|
| 编译缓存（I+M） | B6a+B5a+B5b+B6b+C5b | `compile()` tmp+rename 原子替换、写失败如实抛；`loadableInfos()` require 容错自愈（ParseError/非法形状按无缓存回退注册表，绝不炸穿引导）；flush 返回值如实上报 |
| upgrade 命令 | C2+C1+C3b+C5c | `--all` 捕 `\Throwable` 单插件失败不中止整批；注册表缺失降级磁盘扫描；`--force` 描述如实化（会重跑钩子）；单名磁盘缺失显式报错 EXIT=1 |
| CRUD 生成器 | D5+D5b+D6a | 标记区拒绝落盘裸块；snake 归一撞车检测（fx_article/fx_articles 同模型拒绝生成防静默互覆）；mkdir/写文件失败如实抛 CrudException |
| 生命周期 | A7+A10+A11 | purgeFrontend 失败残留告警（不再静默丢失）；Lifecycle 契约 docblock 写入锁不可重入/外部副作用禁忌（含 A5 硬杀窗口注记）；六入口锁接线参数化测试 |
| 清单/依赖 | B1a+B1b | info.json 字段长度校验对齐 DB 列宽；dependents 排除自引用防卸载死循环 |
| 命令提示 | C5a | enable 同步前端产物后输出「生产环境请执行 npm run build」 |
| HTTP/前端 | D1+D4a+D4b | 列表页 `dependentsMap()` 一次反查消除 N+1（恒定 3 次注册表查询与行数无关）；upgrade 空跑如实提示「已是最新版本」；卸载弹窗标注「默认回滚业务表（也可保留数据）」 |

## 实现要点与计划偏差

1. **refreshMenusAndPermissions 入锁（计划偏差）**：规划时发现它是与五生命周期入口同级的注册表写者，一并纳入互斥——六入口而非五入口。
2. **提交粒度偏差**：计划原文「一条一提交」，执行时多条修复交叉落于同一文件（如 AddonUpgradeCommand 承载 C1/C2/C3b/C5c），经用户确认改为**按主题 4 个 commit**：`732272b` 编译缓存原子写与假成功治理、`c9a4388` addon:upgrade 命令治理、`befe155` CRUD 生成器落盘防护、`2fc6fc5` A/B/C/D 组 Minor 收尾。
3. **AUDIT-D1 测试口径**：`AddonApiTest` 同文件早前用例的 fixture 残留磁盘（afterEach 不清目录）且 `scan()` 全量 glob，行数断言改为按 `nplus` 前缀过滤；查询数断言（≤3）保持——查询数恒定与行数无关恰是 D1 修复的意义。
4. **并发测试故障注入经验**：chmod 0555 必须落在新建条目的**直接父目录**（容器 uid 1000 无 DAC_OVERRIDE）；`@` 抑制必须用于注入点（PHP warning 转 ErrorException）；`Log::spy()` 可拦截 `logger()->warning`。
5. **锁实测方法论**：纯 HTTP 并发难命中锁竞争（状态机预检查在锁外先拦），确定性方案 = 容器内持锁进程（tinker）+ HTTP 请求测「另一操作」快速失败。

## 待办与遗留

**有意遗留的 7 条 Minor**（低风险，修复收益/成本比不划算，另行排期）：

| id | 问题 | 备注 |
|---|---|---|
| A1 | 磁盘直删的已装插件 disable/uninstall 双重死锁，注册行残留 | 需「注册表先行」的强制清理路径，涉及语义决策 |
| A2 | 跨插件菜单名占用检查 TOCTOU（并发安装同名菜单） | 需菜单名唯一约束或安装期全局锁，窗口极窄 |
| A3 | uninstall 钩子抛错后重试会双执行；确定性抛错则永远卸不掉 | 需钩子执行标记表，属新基建 |
| A4 | enable/disable 钩子失败 → update 已提交、重试被状态机挡 | 需状态先写后钩子的顺序反转，波及面大 |
| A6 | 残留 `.tmp/.bak` 目录被前端 glob 编入构建产物 | 需 deleteDir 失败硬校验 + glob 排除模式 |
| A8 | 崩溃持锁 600s 内报「另一操作」但不提示最长等待时长 | 纯文案，与 A9 折中绑定 |
| C3a | `--force` 版本回退时注册表 version 向下写回与 schema 漂移 | 需回退防护校验 |

**批次一残留窗口注记**：`compile()` 原子写消除了半写/撕裂面（B6a），但「读 DB 构建映射 → 写盘」之间与并发 disable/uninstall 的交错窗口仍在（陈旧映射可能落盘，直到下次 flush）——窗口收窄至毫秒级，彻底消除需 compile 拿插件锁或写盘前版本比对，记入遗留权衡（同 A9 折中逻辑）。

**M9 待办（本审计外溢）**：D6b——全局异常信封对 NotFoundHttpException 做中文映射（bootstrap/app.php 一处修，勿在 stub 独修）。

**下一步**：M9 交互打磨（批量操作、筛选器、表单体验、空态/骨架屏）——另起会话 grilling 后定范围。
