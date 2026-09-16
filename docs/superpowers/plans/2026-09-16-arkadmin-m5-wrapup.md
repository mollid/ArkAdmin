# ArkAdmin M5 CRUD 生成器 — 验收记录

> 日期：2026-09-16 ｜ 计划：`2026-09-16-arkadmin-m5-crud-generator.md` ｜ 状态：**完成（GUI 走查待手工补充）**

## 里程碑完成标志

设计文档 §10 M5 完成标志「**对新表一条命令生成可用的完整模块**」，两层验证：

1. **自动化**：`CrudEndToEndTest` 全流程——fixture 插件 + 真实建表 → `ark:crud` → `addon:install` → 菜单树出现 `fx.item`、权限 `addon.fx.item.*` 入库 → HTTP CRUD 全通（store/list+keyword/show/update 部分语义/max 校验 422/destroy）→ 卸载干净。
2. **开发库实测**：对 cms 插件真实生成 `cms_notices` 模块（`ark:crud --table=cms_notices`，`--addon` 按前缀自动推断）——11 个文件落位、菜单「公告管理」经 `refreshMenusAndPermissions` 即刻入库、前端产物自愈同步。首次运行暴露并修复了「menus 标记缺失时留下半生成状态」缺陷（前置校验提前到任何写入之前，附回归用例）。
   **事后处置**：按计划「演示产物不进库」，生成的 Notice 模块已从仓库回退（该表无迁移，入库会让新环境安装出引用不存在表的模块）。cms 的 menus 通用标记对保留（未来生成的前置设施）。**待执行的开发库清理**（审批超时未跑，脚本已备好）：
   ```bash
   D="docker compose -f docker/docker-compose.yml exec -T -u 1000:1000 php sh -c"
   $D "cd /var/www/server && php artisan tinker --execute=\"require '_m5_cleanup.php';\""
   rm server/_m5_cleanup.php
   rm -rf admin/src/addons/cms/views/notice admin/src/addons/cms/api/notice.ts
   ```
   脚本内容：删 `cms.notice` 菜单行、清 `addon.cms.notice.*` 权限（含 spatie 关联表）、drop `cms_notices` 表、冲权限缓存。

## 自动化测试

- 后端 Pest：**151 passed / 661 assertions**（M4 基线 143 + M5 新增 8：SchemaReader 3 + 生成器 5，其中端到端 1 例 35 断言）
- 前端 Vitest：**25 passed**（无新增纯函数，生成器为后端 PHP；`isImage` 已按 YAGNI 于 M4 评审删除）

| 新增文件 | 覆盖 |
|---|---|
| `Feature/Crud/SchemaReaderTest.php`（3） | information_schema 列定义/注释/主键/审计列标记、表不存在异常、类型映射全集与未知类型拒绝 |
| `Feature/Crud/CrudGeneratorTest.php`（5） | 全套生成 + `php -l` 语法校验 + 追加块落位、`--force` 幂等与标记对不重复、前缀校验、menus 缺标记不产生半生成 |
| `Feature/Crud/CrudEndToEndTest.php`（1） | §10 完成标志全回路 |

## 实现要点与计划偏差

1. **命名推导唯一出口**：`cms_articles` + addon cms → `Article`/`ItemController` 形态：`Str::studly(Str::singular($remainder))`（计划写 `Str::singularStudly`，该版本 Laravel 无此方法，实测后改）。
2. **三段式职责**：`SchemaReader`（information_schema + `col_description` 列注释 + 主键探测）→ `FieldMapper`（udt_name→php/cast/ts/组件/校验唯一映射点，未知类型显式拒绝）→ `CrudGenerator`（stub 渲染 + 追加器）。FastAdmin `Crud.php` 的 stub+注释驱动思想照搬，MySQL 方言弃用。
3. **追加器**：routes/permissions/lang 用 per-table 标记对（`// ark:crud:{table}:start/end`）幂等替换；menus.php 结构嵌套，需一次性植入通用标记对（缺失时报错给示例——开发库实测该提示即用户所见第一步）。
4. **`AddonInstaller::refreshMenusAndPermissions()`**（新公开方法）：复用 M2 幂等的 syncMenus/createPermissions + 前端自愈，让生成的新菜单/权限**即刻生效**，未安装插件降级为警告（E2E 覆盖两态）。
5. **半生成防护（开发库实测发现的计划外缺陷）**：generate 原先最后才校验 menus 标记，失败时已写入 7 个文件。修复为 `precheckMenus` 先于任何写入，用例断言失败后零残留。
6. **PG 类型映射 v1 边界**：int2/int4/int8、bool、varchar(n)、text、numeric、timestamp/date、jsonb（按文本）；`jsonb` 不做 array cast、`numeric` 透传字符串为已知限制；关联/枚举/图片上传表单联动、迁移生成、软删除列、多语言均显式排除（见计划「范围外」）。
7. 误伤修复：`git add -A` 曾把测试 fixture 与临时探针提交入库，已补 `/server/storage/framework/{addon-fixture,admin-test}/` gitignore 并解除跟踪（`29cb9a3`）。

## GUI 走查（待手工执行）

1. 开发库 `cms_notices` 模块已生成且已入库：登录后侧边栏「内容管理 → 公告管理」应渲染生成页面
2. 列表搜索/分页、新增（标题必填、置顶开关、长文本域）、编辑（PUT 部分语义）、删除
3. `npm run build` 后生成页面应产出独立 chunk

## 遗留与后续

- 图片列 → 素材库选择器的表单联动（`AttachmentPicker.multiple` 预留位）、jsonb 表单编辑器、关联字段下拉
- 生成器 stub 用户级自定义（当前 `server/stubs/crud/*.stub` 仓库级）
- ark:make:addon（按 M4 模板脚手架新插件，与 ark:crud 配套）
