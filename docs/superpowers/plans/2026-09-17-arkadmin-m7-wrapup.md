# ArkAdmin M7 机制完整化 — 验收记录

> 日期：2026-09-17 ｜ 计划：`2026-09-17-arkadmin-m7-mechanism.md` ｜ 规格：`specs/2026-09-17-arkadmin-harness.md` §4 ｜ 状态：**完成（GUI 走查待手工补充）**

## 里程碑完成标志

插件机制自身补完整，四条线全部落地：

1. **依赖拓扑（§4.3）**：新组件 `AddonDependency`——`assertInstallable`（依赖须已安装）/`assertEnableable`（已安装且已启用）+ 磁盘清单 DFS 环检测；反向保护：`disable` 挡已启用依赖方、`uninstall` 挡一切已安装依赖方（文案列出依赖方与处置动作）。`dependencies` 维持 `string[]`（grilling 定案：YAGNI）。
2. **升级机制（§4.2）**：`AddonInstaller::upgrade(name, force)` 升级链 = 迁移 → `upgrade(旧版本)` 钩子（`hook()` 支持参数）→ 菜单/权限补齐 → 前端同步 → 写回注册表 version；各步幂等、失败即抛可重试。CLI `addon:upgrade {name} [--all] [--force]`；`enable()` 补 `isSupported` 复核。**修复迁移盲区**：插件安装后新增的迁移此前无任何执行路径（install 被"已安装"挡死、enable 不跑迁移）。
3. **插件管理 HTTP 界面（§4.1）**：`GET/POST/PUT/DELETE /api/admin/addons`（权限 `system.addon.{index,store,update,destroy}`，update 为 enable/disable/upgrade 统一入口）；列表 = 磁盘扫描 ∪ 注册表（含未安装/磁盘缺失态、可升级标记、依赖/依赖方）；菜单「插件管理」挂 system 组（sort 4）；系统插件 `settings`/`op_logs` 在 HTTP 层禁止 disable/uninstall（前端禁按钮 + 后端拦截，CLI 不受限）。
4. **ark:sync（计划增量）**：`FrameworkSync`（RbacSeeder + MenuSeeder + 系统插件幂等安装）+ `FrameworkCache`（config/route/event 缓存按需重建，自 AddonInstaller 抽取共用）；`addon:upgrade` 与 `db:seed`/界面同语义。M6 踩的「新增框架权限 → 前端按钮消失」从此一条命令解决。

## 自动化测试

- 后端 Pest：**193 passed / 858 assertions**（M6 基线 173 + M7 新增 20：依赖拓扑 6 + 升级 5 + ark:sync 4 + 管理 API 5）；`MenuModelTest` 基线更新（system 子菜单 3→4）
- 前端 Vitest：**30 passed**（+addon 状态判定/构建提示 3）、`vue-tsc -b` 通过、`npm run build` 成功（dist 含独立 addon chunk）

## 开发库实测（HTTP 真实链路）

1. `ark:sync` → 权限/菜单同步、已装系统插件幂等跳过 ✓
2. `GET /addons` → cms/demo/op_logs/settings 四插件，installed/enabled/system 标记正确 ✓
3. `PUT disable/enable demo` → code 0；`PUT upgrade`（同版本）→ `upgraded:false`「已是最新版本」✓
4. `PUT disable settings` → code 1「系统插件不能停用，请使用 CLI 操作」✓
5. 未知 action → 422；`addon:upgrade --all` → 四插件均「已是最新」✓

## 实现要点与计划偏差

1. **升级判定顺序**：`upgrade()` 先判「磁盘版本 <= 注册表版本」返回 null（已最新），再校验框架版本——无可升级内容时不误报支持性错误（测试按此语义修正）。
2. **`--force` 不改版本号**：只补跑迁移/钩子/同步，注册表 version 保持原值（force 的语义是"补内容"不是"改版本"）。
3. **needs_build**：install/upgrade 响应带 `needs_build`（`lastSyncedFrontend` 非空即真），页面用不可自动消失的 `el-alert` 提示 `npm run build`。
4. **测试环境 admin_path**：TestCase 隔离目录无 `src` 子目录时前端同步被正确拒绝（needs_build=false）——API 测试需先 `mkdir admin-test/src`。
5. **卸载确认三选**：`distinguishCancelAndClose` 区分「保留数据 / 全部回滚 / 放弃」，依赖方信息前置展示。
6. FrameworkCache 抽取后 `AddonInstaller` 不再自带缓存重建（`finish/disable/uninstall` 均走注入组件），行为不变（既有 AddonCacheTest 全绿佐证）。

## GUI 走查清单（待用户确认）

1. 「系统管理 → 插件管理」出现，表格列出 4 个插件（cms/demo 可停用卸载，settings/op_logs 带「系统」标且无停用/卸载按钮）
2. 禁用 demo → 其菜单（笔记管理）从侧边栏消失、启用恢复；页面顶部无异常提示
3. 安装一个带前端的新插件（或重装 demo）→ 顶部出现「请执行 npm run build」黄色提示条
4. 无 `system.addon.*` 权限的账号登录 → 侧边栏无「插件管理」入口
5. 卸载确认框三按钮行为：「全部回滚」卸载并回滚业务表 /「保留数据」卸载但保留表 / 右上角关闭不动

## 待办与遗留

- 评审轮（M2–M7 惯例）：新视角 code review → 修复 → 记录追加至本文件「评审轮记录」
- M8（正确性打磨）：全面审计边界用例、权限粒度、事务与并发——另起会话 grilling 后定范围

## 评审轮记录（新视角 code review，用户逐项核实）

**结论：1 Critical + 4 Important 全部接受并修复**（`e2c0a76`），后端 **197 passed / 871 assertions**（+4 条回归用例）、前端 `vue-tsc` + Vitest 30 passed。

| 级别 | 问题 | 修复 |
|---|---|---|
| C1 | `finish()` 不看注册表 enabled 状态，**禁用态插件 upgrade 会把 provider 重新注册回进程**（HTTP 直达，禁用即失效的契约被静默打破） | `finish(info, registerProvider)`，upgrade 传 `$record->enabled`；回归用例断言禁用态升级后 `Event::getListeners` 仍为空 |
| I1 | `upgrade()` 全程未做依赖校验，新版本清单可引入未安装依赖/环 | 版本比较通过后、migrate 前补 `assertInstallable`；用例：升级引入 ghost 依赖被拒且版本写回不发生 |
| I2 | Controller 只捕 AddonException，迁移 SQL/插件钩子/provider boot 的原生异常以 500 破坏信封契约 | 三个写端点补 `catch (\Throwable)` → `report()` + code 1 信封；`finish()` 的 provider 注册仿 `AddonManager::boot` 包 try/catch 降级告警 |
| I3 | 「已安装」双口径（注册表 vs 磁盘）：目录被删的依赖可装出「已启用但依赖不可加载」脏状态 | `assertInstallable/assertEnableable/missing_dependencies` 统一为「注册表存在 **且** 磁盘健在」；磁盘缺失插件的依赖声明无从核验，`dependents()` 已注明局限 |
| I4 | 插件名无字符集边界校验，`../sibling` 可借 basename 一致性越过 addon_path | `mustExistOnDisk()` 入口加 `^[a-z][a-z0-9_]*$` |
| M1/M2/M3/M6/M7 | 命令 `--all` 静默跳过磁盘缺失插件、name 与 --all 互斥未校验、批量失败即中止；列表 O(N²) 重复扫描 + N+1；disk_missing 行丢 system 标记；环检测无 visited 剪枝；前端 uninstall 返回类型撒谎 | 全部修复：显式提示跳过、互斥报错、批量收集失败统一 FAILURE；`dependents()` 支持 scan 复用 + 依赖一次反查；system 按 config 判定；visited 集合；类型改 `null` 并让 `buildNotice` 接受 null |
| M4/M5/M8 顺延 | FrameworkSync 只捕 AddonException；install/upgrade 并发无互斥；FrameworkCache 无直测 | FrameworkSync **刻意**响亮失败（钩子异常是真实缺陷，加注释固化）；并发锁与 FrameworkCache 直测记入 M8（后者会污染测试环境 bootstrap/cache，需先建隔离方案） |

测试经验补充：同一测试进程内 provider 按类名记账（`app()->register` 幂等复用旧实例），跨用例复用同一插件名的 listen 映射不会生效——监听类用例须换独立插件名。
