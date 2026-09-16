# ArkAdmin

ArkAdmin（方舟）：基于 Laravel 重写 FastAdmin 的插件化后台框架。本文件是领域词汇表——所有工程产物（issue 标题、测试名、评审意见、生成器输出）必须使用这里的术语。

## Language

### 插件体系

**插件（Add-on）**:
放在 `addons/<key>/` 的自包含功能单元，含后端（`src/`）与前端（`admin/`）源码，经安装进入系统。
_Avoid_: 扩展、模块、app

**插件 key**:
插件的唯一短标识（如 `cms`），同时是数据表前缀（`cms_`）、命名空间（`Addons\cms\...`）与权限串组成部分的单一真相来源。
_Avoid_: 插件名、slug

**表前缀约定**:
业务表必须以所属插件的 key 为前缀（`cms_articles` ↔ 插件 `cms`），表归属由此约定唯一确定。
_Avoid_: 表命名规范

**迁移外生成**:
CRUD 生成器只为已存在的数据表生成代码，不负责建表/迁移；表必须先行就绪。
_Avoid_: 一键建表

### CRUD 生成器（M5）

**标记对（Marker pair）**:
追加类文件中形如 `// ark:crud:{table}:start` / `// ark:crud:{table}:end` 的注释边界，生成器据其做幂等整块替换；per-table 标记对互不干扰。
_Avoid_: 注释锚、替换区

**通用锚点（Anchor）**:
形如 `// ark:crud:menus:start/end` 的文件级标记对，供未有过生成记录的文件接收第一个追加条目；menus.php 无通用锚点时生成器报错并给出粘贴示例，绝不解析 PHP 数组。
_Avoid_: 自动插入、智能注入

**列 DTO（Column）**:
SchemaReader 从 information_schema 产出的列描述（名称、udt 类型、可空、默认、注释等），是 FieldMapper 的唯一输入。
_Avoid_: 字段数组、schema 数组

**字段映射（FieldMapper）**:
PG `udt_name` → PHP 类型/cast/Element Plus 组件/校验规则的唯一映射点；未知类型拒绝而非猜测。
_Avoid_: 类型转换表（散落的）
