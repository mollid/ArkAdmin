# AGENTS.md — ArkAdmin 工作区指引

## 工作区定位

本工作区是 **ArkAdmin（方舟）** 项目的家：一个基于 Laravel 重写 FastAdmin 的插件化后台框架（Laravel 13 + PHP 8.3 + PostgreSQL + Vue3/Element Plus 前后端分离 + NiuShop 式目录插件机制）。框架代码已创建（M1 骨架：docker 环境、登录/RBAC/菜单接口、前端 SPA 三大系统管理页面；M2 插件系统核心：引导、生命周期、CLI、菜单注入、编译缓存；M3 前端同步：插件前端复制/删除、组件缺失兜底、i18n 命名空间合并、Vitest），设计文档与两个只读参考仓库并存。

```
/home/gdmax/fastadmin/            # 工作区根（git 仓库根）
├── AGENTS.md                     # 本文件
├── docs/superpowers/specs/       # ArkAdmin 设计文档（先读这个）
├── server/                       # ArkAdmin 后端（Laravel 13 + Sanctum + spatie-permission）
├── admin/                        # ArkAdmin 前端（Vue3 + TS + Element Plus + Vite SPA；
│                                 #   src/addons/ 为插件安装时生成的产物，gitignore）
├── addons/                       # 插件源码根（demo 为 M2/M3 验收插件，可作新插件模板）
├── docker/                       # 开发环境编排（php8.3-fpm / nginx / postgres16）
├── fastadmin/                    # 参考仓库 1：FastAdmin（Apache-2.0）
└── niushop/                      # 参考仓库 2：NiuShop（只学机制，禁抄代码）
```

做任何 ArkAdmin 相关工作前，先读 `docs/superpowers/specs/2026-09-14-arkadmin-design.md`——所有架构决策、许可边界、里程碑都在里面。

## 常用命令

- 后端起停：`docker compose -f docker/docker-compose.yml up -d`
- 后端测试：`docker compose -f docker/docker-compose.yml exec -u 1000:1000 php sh -c "cd /var/www/server && php artisan test"`
- 前端开发：`cd admin && npm run dev`（http://localhost:5175，代理 /api → 8080）
- 前端测试：`cd admin && npm test`（Vitest，路由映射等纯函数单测）
- 超管账号：admin / 123456（开发环境种子数据）
- 插件 CLI（前缀同上 `docker compose -f docker/docker-compose.yml exec -u 1000:1000 php sh -c "cd /var/www/server && php artisan ..."`）：`addon:list` / `addon:install {name}` / `addon:uninstall {name} [--keep-data]` / `addon:enable {name}` / `addon:disable {name}` / `addon:cache` / `addon:clear`

## 参考仓库：fastadmin/

FastAdmin v1.6.x（分支 `1.6.x-dev`）：ThinkPHP 5.0 + Bootstrap 3 (AdminLTE) + RequireJS + jQuery 的后台框架。Apache-2.0 协议，机制和代码均可借鉴。中文项目。

- **仓库不含框架本体**：`thinkphp/`、`vendor/`、`public/assets/libs/`、`node_modules/` 均被 gitignore，需 `composer install` + `npm install` + `grunt` 才有完整环境；当前开发机未装 PHP/Composer，`php think` 命令需在 PHP 7.4+ 环境执行
- **本地部署**：`fastadmin/docker/` 有现成的 Nginx + PHP7.4-FPM + MySQL5.7 编排（端口 8080/3306，镜像走国内加速 `docker.1panel.live`），`fastadmin/.env` 已配置好；主机已有其他项目容器占用 8001/3307，注意避让
- **架构分层**：模块 `admin/index/api/common/extra`；后台控制器继承 `fastadmin/application/common/controller/Backend.php`（CRUD trait 在 `fastadmin/application/admin/library/traits/Backend.php`）；双 Auth：管理员 `fastadmin/application/admin/library/Auth.php`、会员 `fastadmin/application/common/library/Auth.php`
- **对 ArkAdmin 最有价值的部分**：`fastadmin/application/admin/command/Crud.php`（一键 CRUD 生成器，M5 里程碑的研读重点）、权限/菜单模型、`Hook::listen` 扩展点埋点方式
- **注意**：其代码用了 `FIND_IN_SET` 等 MySQL 方言，ArkAdmin 用 PostgreSQL，不可照搬

## 参考仓库：niushop/

NiuShop/niucloud-admin 浅克隆（最新提交 91ef604）：ThinkPHP 8 + PHP 8 后端 RESTful API + Vue3/TS/Element Plus/Vite 后台 SPA + uni-app 移动端；商城本体就是一个插件，与第三方插件同机制。

- **对 ArkAdmin 最有价值的部分**：插件目录结构（`niushop/niucloud/addon/shop/`）、插件安装服务（`niushop/niucloud/app/service/core/addon/CoreAddonInstallService.php`，含前端复制与重构建提示）、动态路由（`niushop/admin/src/router/routers.ts` 的 `import.meta.glob` 机制）、info.json 兼容版本声明
- **许可红线**：NiuShop 官方协议禁止发布衍生框架，**只能学习机制，严禁复制其代码**；需要代码时去 Apache-2.0 的 FastAdmin 找对应物或自己写

## 环境备忘

- Docker 29.6.2 + Compose v5.3.1 可用；Docker Hub 直连慢，用国内镜像 `docker.1panel.live`（已验证）
- Node v22 可用；PHP 与 Composer 未装，PHP 相关操作走 Docker 容器
- Gitee 克隆正常；npm 腾讯镜像源、Composer 阿里云镜像源可用
