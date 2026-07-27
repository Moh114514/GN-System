# GN-System CRM

GN-System 是面向医美/医疗代理业务的内部客户管理系统，用于逐步替代分散的
Excel 客户、代理商、订单和结算数据。当前已完成 Phase 1 基础架构：中文后台、
内部用户认证、超级管理员双因素认证、容器化运行、健康检查、备份和持续集成。
业务模块和 Excel 数据迁移将在后续阶段实现。

## 技术基线

- 架构：Laravel 模块化单体
- 后端：PHP 8.3、Laravel 13、Laravel Fortify
- 前端：Livewire 4、Flux UI 2（免费版）、Tailwind CSS 4、Alpine.js
- 数据：PostgreSQL 16、Redis 7
- 运行：Nginx、PHP-FPM、独立 Queue / Scheduler、Vite
- 质量：PHPUnit、Pint、Larastan / PHPStan level 6
- 可观测性与运维：Sentry（可选）、Spatie Backup、存活及就绪检查

PHP、Composer、Node.js、PostgreSQL 和 Redis 全部由 Docker 提供，Windows
主机不需要单独安装这些运行时。

## 首次启动

前置条件是 Git、Docker Desktop，以及已启用的 WSL2 / Linux 容器后端。

```powershell
git clone https://github.com/Moh114514/GN-System.git
Set-Location GN-System
Copy-Item .env.example .env
docker compose up --build -d
```

首次启动会安装 PHP 依赖、生成本地 `APP_KEY` 并执行数据库迁移。服务就绪后：

- 应用入口：<http://localhost:8080>
- Vite 开发服务：<http://localhost:5173>
- 存活检查：<http://localhost:8080/up>
- 就绪检查：<http://localhost:8080/health>
- 运维心跳检查：<http://localhost:8080/health/operations>

PostgreSQL 和 Redis 不暴露主机端口，仅在 Compose 内部网络中通信。若 8080
或 5173 已占用，可在 `.env` 中修改 `APP_PORT` 或 `VITE_PORT`。需要从其他设备
访问开发服务时，将 `VITE_PUBLIC_HOST` 设置为浏览器实际访问的主机名或 IP。

## 创建首个超级管理员

项目不会生成默认账号或默认密码。容器启动后运行交互式命令：

```powershell
docker compose exec app php artisan app:create-admin
```

密码通过隐藏输入读取，不会出现在 shell 历史中。超级管理员首次登录后必须在
“账户安全”中确认 TOTP 双因素认证，之后才能进入仪表盘。公开注册已关闭，
`/register` 返回 404。

## 日常开发

```powershell
# 启动或重建
docker compose up --build -d

# 查看服务状态和日志
docker compose ps
docker compose logs -f app nginx queue scheduler

# 数据库迁移
docker compose exec app php artisan migrate

# 运行完整质量门禁
docker compose exec app composer ci:check
docker compose exec vite npm run build

# 运维心跳（用于确认独立队列和调度容器）
docker compose exec app php artisan app:queue-heartbeat
docker compose exec app php artisan schedule:run

# 停止服务（保留数据库、Redis 和备份卷）
docker compose down
```

本地热更新由 `vite` 服务提供。亮色、暗色和跟随系统三种主题可在“外观设置”
中切换。邮件默认写入应用日志；`SENTRY_LARAVEL_DSN` 为空时 Sentry 不发送事件。

## 备份

Scheduler 每小时执行 PostgreSQL 备份，每天 02:00 执行 PostgreSQL 和
`storage/app/private` 全量备份，03:00 清理，04:00 检查备份健康；备份保留
7 天全部和 30 天每日最新。开发环境备份写入独立 Docker 命名卷：

```powershell
docker compose exec app php artisan backup:run
docker compose exec app php artisan backup:list
docker compose exec app php artisan backup:clean
```

开发环境本地卷不是异地备份。生产环境采用小时级加密备份和异机同步，暂不使用
WAL/PITR。

## 局域网生产部署

生产部署与开发环境完全分离，使用 `compose.production.yaml`、不可变 app/web
镜像、HTTPS、Redis 鉴权和宿主机持久化；不运行 Vite、不挂载源码，也不会在容器
启动时生成 `APP_KEY` 或自动迁移。

服务器准备、环境变量、发布、回退、异机同步和恢复演练见
[局域网生产部署与恢复](docs/operations/production-deployment.md)。不要把
`.env.production`、TLS 私钥或真实凭据提交到 Git。

## 模块边界

业务代码按以下九个命名空间组织：

```text
app/Modules/
├── Auth
├── Customer
├── Agent
├── Order
├── Settlement
├── Reminder
├── Report
├── Config
└── Audit
```

共享技术能力放在 `app/Infrastructure`。当前边界测试禁止业务模块导入其他业务
模块的任意命名空间，也禁止跨模块直接写入数据；Application Contract 和领域事件
是尚未落地的演进方向。Phase 1 只注册模块提供器和导航入口，不提前实现业务模型。
具体规则见[模块边界文档](docs/architecture/module-boundaries.md)。

## 环境变量与安全

复制 `.env.example` 后只在本地修改 `.env`。不得提交密码、令牌、证书、真实
Sentry DSN 或云存储凭据。模板已包含 PostgreSQL、Redis、日志邮件、备份、
Sentry 和可选 S3 配置。

## 分支规范

- `main`：稳定基线
- `develop`：日常集成
- `feature/<topic>`：从 `develop` 创建，验收后合并回 `develop`

CI 在 Pull Request 及推送到 `develop` / `main` 时执行 Composer 校验、Pint、
PHPStan、PHPUnit、前端构建和 Composer / npm 安全审计。

## 项目文档

- [文档导航与权威性说明](docs/README.md)
- [当前项目状态](docs/project-status.md)
- [当前架构概览](docs/architecture/overview.md)
- [CRM 需求文档 v1.9](docs/source/CRM-需求文档-v1.9.md)
- [架构决策记录](docs/adr/README.md)

下一阶段将在这套基础架构上实现实际 CRM 领域模型、权限矩阵和数据迁移。
