# ArkAdmin M5 CRUD 生成器 — 实施计划

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 实现 `php artisan ark:crud --table=cms_xxx [--addon=cms] [--force]`：读 PostgreSQL information_schema，为既有数据表生成迁移外全套模块代码（Model/Service/Controller/Requests + routes/menus/permissions 追加 + 前端 api/views/lang），一条命令产出可用完整模块（M4 的 Category/Article 即目标产物范本）。

**Architecture:** 三层职责分离——`SchemaReader`（information_schema.columns + col_description 列注释 + 主键探测，产出列 DTO）、`FieldMapper`（PG udt_name → PHP 类型/cast/Element Plus 组件/校验规则，唯一映射点）、`CrudGenerator`（stub 渲染 + 按表追加器）。追加类文件（routes/menus/permissions/lang）用 **per-table 标记对**（`// ark:crud:{table}:start/end`）做幂等替换；命令收尾调用 `AddonInstaller::refreshMenusAndPermissions()`（新增公开方法，复用 M2 的 syncMenus/createPermissions 幂等写入）让新菜单/权限**即刻生效**并同步前端产物。FastAdmin `Crud.php`（1795 行，Apache-2.0）的「stub + 列注释驱动标签 + 类型→表单映射」思想照搬，MySQL `SHOW COLUMNS`/`FIND_IN_SET` 方案弃用。

**Tech Stack:** Laravel 13 Artisan + stub 文件（`server/stubs/crud/*.stub`，`strtr` 占位替换）；Pest 3（真实 PG，phpunit.xml 已配 arkadmin_test）；前端沿用既有 Vue3 模板无新依赖。

**Spec:** `docs/superpowers/specs/2026-09-14-arkadmin-design.md` §10 M5 行；参考 `fastadmin/application/admin/command/Crud.php`（研读重点：`getFieldType`/`getFormGroup`/`getLangItem`/`writeToFile`）；产物范本 `addons/cms/`（M4 交付）。

## Global Constraints

- 生成器只写 `addons/<key>/` **源码目录**；`admin/src/addons/<key>/` 是产物，经 refresh 同步，绝不直写
- 表名必须以插件 key 为前缀（`cms_` ↔ addon cms），否则拒绝（§5.4 表前缀约定）
- 迁移不生成：表必须已存在（information_schema 查不到即报错）
- 生成物命名空间 `Addons\<key>\...`、权限串 `addon.<key>.<snake>.{index,store,update,destroy}`、菜单 `view_path=<snake>/index`、API 路径 `/addon/<key>/<kebab>`
- 幂等：同一表重复执行需 `--force`；`--force` 只替换本表标记对内容与他表无关
- 审计列 `id/created_at/updated_at` 不进表单/fillable 规则之外（id 为 PK 跳过，时间戳列跳过表单）
- PG 类型映射只覆盖 CMS 类业务表常用集：int2/int4/int8、bool、varchar(n)、text、numeric、timestamp/date、jsonb（v1 按文本处理）；未知类型拒绝并提示
- 中文提交信息 `type(m5): ...`；测试在 docker 内跑
- NiuShop 禁抄；FastAdmin 可借鉴

## File Structure

```
server/
├── stubs/crud/
│   ├── model.stub / service.stub / controller.stub
│   ├── store_request.stub / update_request.stub
│   ├── api_ts.stub / view_vue.stub            # 前端整文件模板
│   └── parts/                                  # 行级片段（表单控件/表格列/校验规则行）
├── app/Support/Crud/
│   ├── SchemaReader.php                        # information_schema → ColumnDto[]
│   ├── FieldMapper.php                         # udt_name → 映射表（唯一映射点）
│   ├── CrudGenerator.php                       # stub 渲染 + 追加器编排
│   └── Console/ArkCrudCommand.php              # ark:crud
├── app/Support/Addon/AddonInstaller.php        # + refreshMenusAndPermissions(string $name)
└── tests/
    ├── Feature/Crud/SchemaReaderTest.php
    ├── Feature/Crud/CrudGeneratorTest.php      # 生成 + php -l + 幂等 + 追加器
    └── Feature/Crud/CrudEndToEndTest.php       # fixture 插件全回路
addons/cms/
├── routes/admin.php / database/{menus,permissions}.php / admin/lang/zh-cn.ts   # 首次植入标记对
└── (演示产物不进库——cms 现有代码是手写范本，不用生成器重写自己)
```

## 关键设计定案

1. **列 DTO**：`name, udtName, nullable, hasDefault, maxLength, comment`；PK 与 `created_at/updated_at` 标记 `skipForm=true`（id 仍进 Model 主键，时间戳走 timestamps()）。
2. **标签来源**：`col_description('<table>'::regclass, ordinal_position)`；无注释时用蛇形转中文空格标题（`category_id` → `Category id`→ 生成 `栏目ID`? 否——英文项目，标题用 `Str::headline($name)`，如 `Category Id`；注释优先）。
3. **校验规则**：NOT NULL 且无默认且非 PK → `required`；varchar(n) → `max:n`；int → `integer`；bool → `boolean`；text → `string`；numeric(p,s) → `numeric`；timestamp → `nullable|date`。Update 请求全部 `sometimes` 前缀（M4 I1 教训：PUT 部分语义）。
4. **搜索**：首个 varchar/text 列生成 keyword 搜索（PgLike::wrap，复用框架转义）；列表剔除大字段（text 列不出现在列表，仅表单/详情）。
5. **追加器**：标记对格式（注释行，多表共存）：
   ```php
   // ark:crud:fx_items:start
   ...生成内容...
   // ark:crud:fx_items:end
   ```
   无标记对时：routes/permissions/lang 直接在文件尾/末 `}` 前创建标记对；**menus.php 特殊**——必须插在根菜单 `children => [` 数组内，无标记对时报错并给出两行示例让用户粘贴（一次性人工动作，避免解析 PHP 数组的脆弱实现）。
6. **`refreshMenusAndPermissions(string $name)`**（AddonInstaller 新公开方法）：`syncMenus()`（幂等 updateOrCreate）+ `createPermissions()` + `syncFrontend()`（自愈产物），安装/未安装态均可安全调用；未安装抛 AddonException（生成器前置已校验）。
7. **验收标准映射**：T5 端到端测试 = §10 完成标志的自动化形态——fixture 插件 + 真实建表 → `ark:crud` → `addon:install` → HTTP CRUD 全通 → 卸载干净；GUI 走查以 cms 上生成一张真实演示表收尾。

## 范围外（显式排除）

- 关联/枚举/字典生成（FastAdmin enum 与状态列联动）、图片上传字段到素材库的表单联动（v1 图片列走文本路径，M5+ 演进）
- 生成迁移/建表（spec 明确「迁移外」）；软删除列
- 多语言（lang 仅 zh-cn）；树形表格页面（列表统一分页表格，树表沿用 M4 手写范本）

## Self-Review 记录

- **Spec 覆盖**：§10 M5「读 PG information_schema」「迁移外全套」「菜单定义+语言包」「对新表一条命令生成可用模块」→ T1/T2/T3/T4/T5 一一对应。
- **占位符扫描**：stub 全文在 T2/T4 步骤内给出；无 TBD。
- **类型一致性**：`ColumnDto` 字段在 T1 定义、T2/T4 消费；`refreshMenusAndPermissions` 在 T3 定义、T4 命令调用；权限串/菜单 view_path 约定与 M4 产物逐字一致。
