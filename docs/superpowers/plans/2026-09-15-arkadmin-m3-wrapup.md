# ArkAdmin M3 前端同步 — 验收记录

> 日期：2026-09-15 ｜ 计划：`2026-09-15-arkadmin-m3-frontend-sync.md` ｜ 状态：**完成**

## 里程碑完成标志

设计文档 §10 M3 完成标志"**M2 空插件带上一个 Vue 页面跑通**"——demo 插件现带完整前端三件套（views/api/lang），GUI 实测：登录 → 侧边栏"演示插件→便签" → 真实页面（时间线渲染、i18n 文案）→ 新增便签 → 列表刷新，全链路通过。

## 自动化测试

- 后端 Pest（docker 内）：**96 passed, 253 assertions**（M2 收官 91 + M3 新增 5：前端同步 5 项 + 超管权限同步断言并入既有安装用例）
- 前端 Vitest（**M3 首次引入**）：**4 passed**（`admin/tests/router.test.ts`：框架/插件映射、双方 miss → missing 兜底）
- `npx vue-tsc -b` + `npm run build`：类型检查与构建通过，demo 页面产出独立 chunk（`dist/assets/note-*.js`）

## 手工验收（开发库 + Vite dev 5175 + Playwright）

1. `addon:install demo` → 输出"前端已同步至 admin/src/addons/demo，生产环境请执行 cd admin && npm run build 完成后台构建"；`admin/src/addons/demo/views/note/index.vue` 就位（Vite dev 热更新直接生效，无需构建）✓
2. 超管登录 → 侧边栏"演示插件→便签" → `/demo/note` 真实页面：标题/按钮/空态文案全部来自 `demo.note.*` 命名空间（i18n 合并生效）；列表含 M2 事件写入的 `login:admin` ✓
3. 新增闭环："写一条"→ 对话框 → 输入" M3 前端同步验收便签" → 时间线出现 #1 ✓（截图 `gui-test-screenshots/m3_t1_note_page.png`）
4. 权限裁剪：无角色账号 op01 登录——侧边栏仅"控制台"，"演示插件"整组不可见（菜单绑 `addon.demo.note.index`）✓
5. 组件缺失兜底：menus 插入 `view_path='ghost/index'` 的插件菜单 → 访问 `/demo/ghost` 渲染"组件缺失，请执行 npm run build"提示页 ✓（截图 `m3_t2_missing_fallback.png`），清理测试菜单
6. 卸载回路：`addon:disable` + `addon:uninstall demo` → `admin/src/addons/demo` 删除、菜单消失；重装 → 页面/菜单/权限完整恢复（开发库保持已安装态）✓

## 实现要点与计划偏差

1. **`resolveView` 抽到独立文件 `src/app/router/resolveView.ts`**（计划原定放 router/index.ts）：Vitest 默认 node 环境导入 router 模块会执行 `createWebHistory()`（无 window 报错）；拆出纯函数模块后测试不触发 router 创建，也符合单一职责。glob 以默认参数注入，测试传 fake map 零文件系统副作用
2. **i18n 类型**：vue-i18n 不导出单数 `LocaleMessage`，用 `LocaleMessages<any>[string]` 索引访问取 `LocalePack` 类型
3. **超管权限同步修复（计划外发现）**：GUI 验收发现插件新权限未 sync 给 super_admin——接口有 `Gate::before` 旁路不受影响，但前端 `has()` 是字符串包含检查、按钮级权限失效。`AddonInstaller::createPermissions` 末尾延续 RbacSeeder 语义（super_admin = 全部权限）sync 之，附回归断言
4. 测试文件系统隔离：`config('arkadmin.admin_path')` 注入临时目录 + Pest 全局 afterEach 清空 `<admin_path>/src/addons`（复制产物不随 DB 事务回滚；目录是生成物，源码唯一来源 `addons/<key>/admin`）
5. `admin/src/addons/` 进根 `.gitignore`（生成产物）；CLI 构建提示仅在插件带 `admin/` 目录时输出（不误导纯后端插件）

## 遗留与后续

- **M4 CMS 插件**（§9）：栏目树、文章富文本、素材库消费——首个真实业务插件，直接吃 M2+M3 全部机制
- 生产环境"一条命令构建"流程（§11 对策）：当前手工 `cd admin && npm run build`，后续可加 CI/云端预编译
- 多语言目录（lang/en.ts）：当前仅 zh-cn，出现第二 locale 时扩展 `lang/index.ts` 合并逻辑
- HTTP 插件管理界面仍是 backlog 候选
