# ArkAdmin M4 CMS 插件 — 验收记录

> 日期：2026-09-16 ｜ 计划：`2026-09-16-arkadmin-m4-cms.md` ｜ 状态：**完成（GUI 走查待手工补充，见下）**

## 里程碑完成标志

设计文档 §10 M4 完成标志「**§9 全部通过**」：

- §9 功能交付：框架级素材库（attachments 表/上传/列表/删除/`attachment.saved` 埋点/素材库页面/共享选择器）+ CMS 插件（树形栏目、富文本文章 quill、封面、标签 jsonb、草稿/发布）。
- §9 验收流程 1–6 全部自动化（`CmsLifecycleTest` §9-3/4/5/6/keep-data/重装六用例），1–2 另经开发库真实安装 + HTTP 端到端脚本验证；7（Pest 覆盖全回路）即本组用例本身。
- **GUI 自动化受阻**：Playwright Chromium 缺系统库 `libnspr4.so` 且 sudo 需密码，截图走查改为手工清单（见「遗留」）。以三项替代佐证：HTTP 端到端全链路、`npm run build` 产出 `article/category/attachment` 独立 chunk、`vue-tsc` 对复制进 `admin/src/addons/cms` 的真实源文件类型检查通过。

## 自动化测试

- 后端 Pest（docker 内，arkadmin_test）：**124 passed / 447 assertions**（M3 基线 102 + M4 新增 22：素材库 6 + CMS 冒烟 1 + 栏目 4 + 文章 5 + §9 回路 6）
- 前端 Vitest：**29 passed**（基线 16 + `tests/attachment.test.ts` 13）
- `npx vue-tsc -b`：通过（含 quill 自带类型，未需要 d.ts 兜底）；`npm run build`：通过

M4 新增测试文件/用例组：

| 文件 | 覆盖 |
|---|---|
| `AttachmentTest.php`（6） | 上传（行/文件/尺寸/url/事件）、白名单与超限 422、列表过滤+通配符字面量、删除连文件、403 信封、权限与素材库菜单种子 |
| `CmsLifecycleTest.php`（16） | 安装冒烟（表/菜单/权限/种子）、栏目树与成环守卫、栏目删除守卫、文章校验 422、jsonb tags 往返、published_at 收口、列表过滤与 content 剔除、§9-3 菜单裁剪、§9-4 disable、§9-5 enable、§9-6 卸载（含前端产物清除）、keep-data、重装 |

## 开发库手工/HTTP 验收（docker 实测）

1. `php artisan migrate --force`（attachments 迁移）+ `db:seed`（素材库菜单/权限）→ `addon:install cms` → 输出前端同步与构建提示，`admin/src/addons/cms/{views,api,lang,components}` 就位 ✓
2. `storage:link` 后经 HTTP 上传 PNG：`code=0`、`url=http://localhost:8080/storage/attachments/202609/xxx.png`、宽高 320×200 识别；`curl` 该 URL → **HTTP 200**（nginx 直读软链）✓
3. HTTP 端到端：登录 → 建栏目「公司动态」→ 建文章（封面=素材 path、tags=`["m4","验收"]`、status=1）→ 列表 keyword 命中、`cover_url` 正确、tags 数组往返 ✓
4. `APP_URL=http://localhost:8080` 已写入 `.env`（dev）与 `.env.example`；`.gitignore` 增 `/server/public/storage/` ✓
5. 开发库当前状态：demo + cms 均已安装启用（保留验收数据：默认栏目、公司动态、示例/验收文章、素材库 2 图）

## 实现要点与计划偏差

1. **插件模型必须显式 `$table`**：Eloquent 按类名推导表名，`Category` → `categories` 而非 `cms_categories`（冒烟测试当场抓住），`Category/Article` 均已显式声明。
2. **tags 选 jsonb 而非 text[]**（§5.4 两者皆可）：Eloquent 内建 `array` cast 与 PG jsonb 直接兼容，免写自定义 PG array cast；Pest 断言 jsonb 往返为数组。
3. **业务守卫模式**：服务层抛插件级 `CmsException` → 控制器 `fail(1, msg)`（HTTP 200 + code 1），沿用框架 AdminController 业务错误约定；成环守卫用 `descendantIds` 迭代实现。
4. **`cover_url` 访问器**：文章行封面存 `attachments.path`（提交值），模型追加 `cover_url`（与 `Attachment::url` 同规则推导）供前端展示，避免前端自行拼 URL 的环境耦合。
5. **测试基建已知形态（新增注释锁定）**：Sanctum guard 在同一测试进程内缓存首个解析用户——超管先发起带认证请求后，无权限账号的请求会复用缓存身份导致 403 断言失效。所有「无权限 403」用例必须让无权限账号发起本测试内首个带认证请求（§9-3 中超管菜单树改走 `MenuService` 不经 HTTP）。生产环境每请求独立进程，无此问题。
6. **计划偏差**：T3 冒烟测试不含前端产物断言（T6 前端目录当时不存在，断言移入 §9-6 卸载用例）；T4「栏目删除守卫」用例依赖 T5 文章接口，随 T5 转绿；`PgLike` 由 AdminController 重构上提为 `App\Support\Http\PgLike`，CMS 服务共用。
7. **富文本选型**：`quill@2.0.3`（npm 腾讯镜像可达），插件内薄封装 `RichEditor.vue`，正文插图经框架素材库上传后插入 URL；封面消费 `AttachmentPicker`（框架共享组件）。

## GUI 走查（待手工执行）

自动化受环境限制，按以下清单走查（dev：`npm run dev` 5175 + nginx 8080，账号 admin/123456）：

1. 侧边栏出现「内容管理」「素材库」（sort：控制台 < 素材库 < 内容管理 < 系统管理）
2. 素材库：上传 → 卡片缩略图/尺寸/大小 → 复制链接 → 删除
3. 栏目管理：树表（默认栏目/公司动态）→ 新建子栏目 → 编辑/删除守卫提示
4. 文章管理：新建文章（标题/栏目/标签回车创建/**封面「选择」打开素材选择器**/正文 quill 编辑与插图）→ 发布 → 列表缩略图与 tags
5. 权限裁剪账号：侧边栏仅见文章不见栏目（对应 §9-3 自动化）
6. CLI `addon:disable cms` → 刷新菜单消失；`addon:enable` → 恢复；`addon:uninstall` → 菜单/表/前端产物清除

## 评审轮记录（2026-09-16，新视角代码评审后加固）

评审结论 1 Critical / 6 Important / 9 Minor。核实后全部接受并修复（除按 YAGNI 降级两项，见末尾）。修复后全量：后端 **130 passed / 490 assertions**、前端 **29 passed**、`vue-tsc -b` + `npm run build` 通过。

| 项 | 严重度 | 修复 |
|---|---|---|
| 栏目树 `nest` 每节点对子树递归两次（一次求文章数一次取 children），深层树指数级调用可挂死 categories 接口（认证后低成本 DoS） | Critical | 先算一次 `children` 复用求和；节点索引改为 `groupBy('parent_id')` 预建，整体 O(n)；新增 30 层链回归用例 |
| `normalize` 破坏 PUT 部分语义：不传 tags 被清空；已发布文章每次编辑 `published_at` 被刷新为当前时间 | Important | 拆分 `normalizeForStore/normalizeForUpdate`：仅处理显式提交的字段；发布时间只在"首次发布"落 now()，转草稿清空；新增 PUT 部分语义回归用例 |
| 富文本 content 原样入库 + RichEditor `innerHTML` 直插 → 低权编辑者可注入存储型脚本（管理员打开编辑时执行） | Important | 新增 `Addons\cms\Support\RichTextSanitizer`（DOMDocument 白名单：允许 Quill 产出标签、script/style/iframe/svg 整棵丢弃、事件属性剥离、href/src 仅安全协议），store/update 入库前收口 + 载荷回归用例；RichEditor 改 `clipboard.convert → setContents` 走 Quill 数据模型（兼修直改 DOM 失步） |
| 附件删除无引用联动：CMS 封面成死链且无 `attachment.deleted` 埋点 | Important | 框架新增公开埋点 `AttachmentDeleted`（§5.5 事件清单）；CMS `ClearDeletedCover` 监听清空引用该素材的封面（正文插图 URL 无法反查，记录为已知限制）；联动用例覆盖 |
| 栏目树 `article_count`（含子孙）与文章列表过滤（精确栏目）语义不一致 | Important | 列表过滤改为 `whereIn(subtreeIds)` 含子孙，与树计数一致；新增跨级计数 + 过滤断言 |
| `AttachmentPicker` 多选跨页丢选择、关闭后残留勾选 | Important | 改 `Map` 累积跨页选择；对话框关闭即清空 |
| 上传白名单实际走客户端可伪造的 mime 链（Laravel `mimes` 规则经 `guessExtension` 取客户端 mime，实测 `.php` 名 PNG 被嗅探为 `application/x-php`） | Important（评审后实测升级） | 弃用 `mimes` 规则：config 改「内容嗅探 mime → 扩展名」映射表，finfo 嗅探统一收口 `AttachmentService::sniffedMime`，入库 mime/扩展名均以内容为准；新增双向用例（JPEG 内容配 `.php` 名 → 存 .jpg；PHP 内容配 `.png` 名 → 422） |
| `getClientOriginalExtension` 回退为不信任输入的死代码；`getimagesize` 对非本地磁盘会炸；文件删除失败静默；install 种子无事务；素材库页删除无 try/catch；文章页「未分类」过滤项语义误导；i18n 硬编码；正文净化归属未记录 | Minor | 逐一修复/带过：删回退、限 local 磁盘取宽高、删除失败记 warning、种子包 `DB::transaction`、补 catch、去伪节点 + deleteConfirm 键、归属写入本记录 |

**降级/拒绝项**：`cover_url` 不反查 `attachments.disk` 列（封面统一默认素材盘，文档化）；正文插图死链的反查清理（URL 无引用登记，留待素材引用表方案）。

**已知覆盖边界**：`RichTextSanitizer` 为自研白名单净化，面向内部低权编辑者威胁模型，不承诺对抗专业 mXSS；富文本净化责任方（服务端收口）已在此定责，后续插件沿用该类或自建。

## 遗留与后续

- **GUI 截图走查**：待安装 Chromium 系统依赖（`sudo apt-get install libnspr4 libnss3` 等）后可恢复 Playwright 自动化
- **M5 CRUD 生成器**（§10）：`ark:crud` 读 PG information_schema 生成 Controller/Service/Model/Request + Vue 页面 + 菜单/语言包；CMS 的 Category/Article 即生成器的目标产物范本
- HTTP 插件管理界面、`settings`/`admin_op_logs` 表（backlog 候选）
- admin/dist 主 chunk >500KB 警告（Element Plus 全量引入既有形态），可在 M5+ 引入按需导入时一并处理
