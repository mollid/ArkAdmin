# ArkAdmin M2 待办清单（M1 遗留分诊）

> 来源：M1 全分支最终审查（2026-09-15）。29 条延迟 Minor 逐条复核后均不阻塞 M1 合入；本清单按主题归并为 M2 处理项。M2 首个任务应为插件系统核心（设计文档 §10），下列项按需插入。
>
> **2026-09-15 M1 收尾完成**：除"生产化"一组（按约定留给部署里程碑）与 5174 端口事项（用户自理）外全部处理完毕，Pest 44 例全绿。

## 生产化（绑定为一项，M2+ 部署里程碑）

- [ ] admin/dist 生产服务方案：nginx 增加 SPA 静态服务与 /api 同域反代（当前 nginx.conf 只服务 server/public，dev 形态）
- [ ] 发布并收紧 `config/cors.php`（框架默认 api/* + 允许 * ，dev 经 vite proxy 同源风险小，上生产前必须白名单化）
- [ ] Sanctum token 过期时间（当前 `expiration => null` 永不过期）
- [x] dev 端口收紧：5432/8080 绑 127.0.0.1；PHP location 补 `try_files $uri =404;`（M5 后随 dev 加固落地，2026-09-16）
- [ ] 超管密码 123456 改环境注入

## 种子与骨架残留

- [x] `DatabaseSeeder` 接入 RbacSeeder + MenuSeeder，删除 web 侧 User factory 播种（当前 `php artisan db:seed` 种不出 admin）
- [x] 清理骨架死代码：web.php welcome 路由、User 模型、icons.svg
- [x] dashboard 页文案 "Laravel 12" → 13

## 后端加固（一行级）

- [x] 列表 `per_page` 加 `integer|min:1|max:100`；`ilike` 通配符 `%`/`_` 转义
- [x] AdminUpdateRequest username 显式 null 边角（`nullable` 或前端契约）
- [x] role/menu destroy 包事务；menu 递归删除同
- [x] 登录 timing 侧信道（不存在用户也跑一次 Hash::check）
- [x] Admin status 补 int cast；permission 查询 NULL 健壮性
- [x] unique/exists 校验按 guard_name 收窄（引入第二 guard 前必做）
- [x] `forgetPermissionsCacheId`→`forgetCachedPermissions` 类 API 名以 vendor 为准（已正确，备忘）

## 测试补强

- [x] setRelation 角色回填契约回归测试；末位超管防呆测试
- [x] Pest.php uses 覆盖 Unit 目录；EnvelopeDemo 类移入测试函数作用域

## 前端体验

- [x] logout 移除已注册动态路由（换账号残留）；已登录访问 /login 重定向
- [x] login submit / ElMessageBox 取消补 catch（unhandled rejection）
- [x] 管理员页 roleApi.list 403 时角色下拉处理（禁用或保留原值）
- [x] menu 页移除冗余 `as never`

## 已知设计取舍（无需改，备忘）

- 超管 me() 返回全量权限列表（非 `['*']`），前端 has() 双检兼容
- update 缺 roles/permissions/parent_id 即清空/归零——PUT 全量语义，前端契约遵守
- nest() 在 MenuController 与 MenuService 双处维护（语义不同：管理树不剪空目录），不可盲并
- package-lock.json 的 resolved 锁定腾讯镜像 URL（离线/CI 环境知悉）

## 运维

- [x] 用户浏览器走查完成后：清理 dev 库 half/x123456 联调账号及 admin_mgr 角色（MenuFilterTest 有自动化等价覆盖，不影响测试）
- [ ] 主机 5174 曾被误杀的 dev server（其他项目）如仍需要，由用户自行重启
