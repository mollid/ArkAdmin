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

> 第二轮评审补充（唯一需 GUI 复核的改动）：新建文章时用工具栏加「项目符号」「待办勾选」「代码块」，保存后重新打开编辑，三者结构应保持原样（列表容器按 `data-list` 归一为 `UL`、代码块 `div.ql-code-block*` 保留）。源码依据见第二轮评审记录。

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

## 评审轮记录（第二轮，2026-09-16，新视角对抗式复审）

复审方法：重读 M4 全部产物（框架素材库 + CMS 后端/前端/测试），对可疑点写一次性探针实测（探针文件已删除，工作树干净），并逐条核对 round-1 修复的实际落点。基线数字复核属实（130 passed / 490 assertions）。本轮结论：3 Important 已修，7 Minor 记录待裁。修复后全量：后端 **137 passed / 523 assertions**、前端 **25 passed**、`vue-tsc -b` 通过。

> 第三轮独立复核（2026-09-16，接本轮修订后原样验证，含反向验证）：
> 1. **本轮净化保真修复成立且必要**——用 jsdom 注入 Quill 2.0.3 UMD 构建实测（最接近浏览器行为）：`<ol><li data-list="bullet">` 去掉 `data-list` 后被解析为**有序列表**，证明 round-1 的净化器确实造成「保存即降级」，本轮修复命中真实缺陷；`<ul>` 归一改写亦经实测确认生效（同容器混用 ordered/bullet 时刻意不改写，与其单测一致）。
> 2. **本轮文档中「前端 29 passed」为过期数字**，实测 25 passed：round-1 的 `isImage` 及其 4 个用例已被本轮删除。核对 `admin/src`、`addons/cms/admin`、`admin/tests` 全仓 0 引用（后端 `type=image` 过滤由 mime 判断承担），删除属正确 YAGNI 处置——该函数系 round-1 依据评审建议添加的投机代码。
> 3. **`data-language` 不入白名单为可接受边界**（实测：Quill 2 按 `div.ql-code-block` class 识别代码块并自行回填 `data-language="plain"`；CMS 工具栏无语言选择器）。已将该边界以断言形式锁定在 `CmsLifecycleTest` 中，将来引入语言支持时该断言会失败并提示同步放开。
> 4. 基线复核后：后端 **143 passed / 553 assertions**（本轮记录 137 + 复核新增净化保真 HTTP 用例）、前端 **25 passed**。

| 项 | 严重度 | 证据 | 修复 |
|---|---|---|---|
| 净化白名单未覆盖 Quill 2 真实产出：`li[data-list]` 被剥离、代码块 `div.ql-code-block*` 被解包 → 正文「保存即降级」（项目符号/待办变有序列表、代码块变纯文本） | Important | 探针实测 `<ol><li data-list="bullet">甲</li></ol>` → `<ol><li>甲</li></ol>`；`<div class="ql-code-block-container"><div class="ql-code-block">echo 1;</div></div>` → 纯文本 `echo 1;`。Quill 2.0.3 源码（`quill.js.map` 的 sourcesContent）：`ListItem.formats` 读 `data-list`、`ListContainer.tagName='OL'`、剪贴板 `['ol, ul', matchList]` 只按容器标签判型、`['pre', matchCodeBlock]` | 属性白名单加 `data-list`（取值限 bullet/ordered/checked/unchecked）；带 `ql-code-block*` class 的 div 保留结构（其余 div 仍解包）；非有序列表容器按 `data-list` 归一改写为 `ul`。新增 `tests/Unit/RichTextSanitizerTest.php`（4 例，含"不放宽安全边界"反向断言）+ HTTP 保存读回用例 |
| PUT 文章显式 `tags: null`（`nullable` 规则放行）→ `array_values(null)` TypeError **500** | Important | 探针实测 HTTP 500 + `array_values(): Argument #1 must be of type array, null given`；`normalizeForStore` 有 `?? []` 兜底而 round-1 新拆的 `normalizeForUpdate` 没有 | `array_values((array) ($data['tags'] ?? []))`；store/update 双路径用例 |
| 栏目 `parent_id` 无存在性校验（同模块 `article.category_id` 有 `Rule::exists`），可造出树中不可见、界面无法编辑/删除的孤儿栏目；显式 `parent_id: null` 撞 `NOT NULL` 变 500 | Important | 探针实测 `parent_id=99999` → code 0、树中不可见、DB 有行；null 用例先红（500/200）后绿 | 抽 `parentIdRules()`（0=顶级、>0 必须存在，store/update 共用）+ null 归一为 0；用例覆盖 store/update/树可见性 |

**本轮 Minor 修复（确认范围后全部处理）**：

| 项 | 处理 |
|---|---|
| PUT 契约 `status` 为 `required`，与「部分语义」矛盾 | 改 `sometimes\|required\|integer\|in:0,1`（`ArticleUpdateRequest` 类注释写明字段一律 sometimes）；用例：不传 status 不再 422 且状态/发布时间不变，显式 `status:null` 仍 422 |
| `content` 无长度上限 | `Article::CONTENT_MAX = 200000`（字符，策略值，两个 FormRequest 共用）；新增超长 422 用例 |
| `isImage()` 无任何业务调用方 | **删除**（YAGNI）：`arkadmin.attachment.mimes` 白名单当前仅图片，按 mime 分流渲染不可达；同步删 4 条单测。计划 T2 接口表该条目以本节为准 |
| `AttachmentPicker.multiple` 无调用方 | **保留并钉死结论**：组件是框架公开件（§7.1）、`multiple` 是 T2 计划产物、round-1 已按其语义修跨页丢选；组件注释写明「预留（多图字段/图集），若 M5 CRUD 生成器的图片组字段仍未消费，按 YAGNI 复审移除」，避免下轮重复评审 |
| 4 个插件 i18n 键零引用 | 删 `nameRequired/titleRequired/allCategories/publishOnSave`（页面改用按钮 `:disabled`；`allCategories` 对应 round-1 已移除的伪节点；`publishOnSave` 无展示位） |
| i18n 硬编码残留 | 框架语言包补 `common.tip / deleteConfirm / attachmentLibrary / copyFailed`；插件语言包补 `cms.category.{topLevel,yes,no}`；素材库页标题与删除确认、插件页「提示」「是/否」「顶级」全部走 `t()` |
| 前端 `load()/openDialog()/copy()` 无 catch | 素材库页、共享选择器、CMS 两页统一 try/catch（拦截器已提示，仅吞 rejection 并保持现有数据）；`copy` 失败提示 `common.copyFailed`（非安全上下文 clipboard 会 reject） |
| 服务层无白名单兜底 | `AttachmentService::store` 改为「嗅探 mime 不在白名单 → 抛 `InvalidArgumentException`」，不再回落 `.bin`；用例覆盖（直调抛异常、不落库不留文件） |
| 写盘与建行无事务 | 建行异常时回收已写盘文件后重抛；用模型 `creating` 监听模拟失败断言无孤儿文件（用完 `flushEventListeners`，避免静态监听污染同进程后续用例） |
| `AttachmentDeleted` 队列风险 | 事件注释标注「仅同步消费」：模型行已删，监听器入队后 `SerializesModels` 无法水合 |
| 正文插图绝对 URL（换环境失效） | 改用**读取期重写**：`Article::getContentAttribute` 把 content 内 `//<任意主机>/storage/` 归一为当前素材盘前缀（外链不动）。未采用「存相对路径」——前端在 EdgeOne 与后端分域部署时相对路径会解析到前端域，dev 编辑器还需额外代理 `/storage`。用例：旧主机 → 当前前缀、外链保持 |
| 计划文档 `extensions` 过期表述 | `2026-09-16-arkadmin-m4-cms.md` 的 Interfaces 与代码样例加「实施期修正」标注，指向 finfo 内容嗅探白名单方案 |

**已核实非问题（避免后续重复评审）**：Element Plus 清空选择回写 `undefined`（`DEFAULT_VALUE_ON_CLEAR = void 0`）且 Laravel `ConvertEmptyStringsToNull` 兜住空串 → 清空筛选参数不会 422（实测 `category_id=''`/`status=''` → 200 code 0）；`href` 的 tab/换行协议绕过不成立（libxml 序列化时 URI 属性控制字符转义为 `%09`，`parse_url` scheme 为 NULL）。

**验证边界**：列表容器改写与代码块保留的依据是 Quill 2.0.3 源码，未做浏览器实跑（项目未装 jsdom，Playwright 环境未恢复）；`clipboard.convert` 的真实往返仍需 GUI 走查确认。

## 遗留与后续

- **GUI 截图走查**：待安装 Chromium 系统依赖（`sudo apt-get install libnspr4 libnss3` 等）后可恢复 Playwright 自动化
- **M5 CRUD 生成器**（§10）：`ark:crud` 读 PG information_schema 生成 Controller/Service/Model/Request + Vue 页面 + 菜单/语言包；CMS 的 Category/Article 即生成器的目标产物范本
- HTTP 插件管理界面、`settings`/`admin_op_logs` 表（backlog 候选）
- admin/dist 主 chunk >500KB 警告（Element Plus 全量引入既有形态），可在 M5+ 引入按需导入时一并处理
