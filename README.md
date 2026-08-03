# GN-System CRM

GN-System 是面向医美/医疗代理业务的内部客户管理系统，用于逐步替代分散的
Excel 客户、代理商、订单和结算数据。当前已完成 Phase 1 基础架构、Phase 2
核心数据与导入能力、Phase 3 客户全生命周期及 Phase 4 代理商与推广费核算核心
闭环、Phase 5 月结、结算单与主动提醒中心，以及 Phase 6 多维查询、真实数据看板
和配置中心，并已启用独立订单中心；真实历史数据迁移仍待正式源文件、错误处理和抽样核对。

## 技术基线

- 架构：Laravel 模块化单体
- 后端：PHP 8.3、Laravel 13、Laravel Fortify
- 前端：Livewire 4、Flux UI 2（免费版）、Tailwind CSS 4、Alpine.js、ECharts
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

首次运行测试前，创建本地测试环境文件：

```powershell
Copy-Item .env.testing.example .env.testing
```

根据本机环境在被 Git 忽略的 `.env.testing` 中补充测试数据库密码。PostgreSQL 首次创建全新数据卷时，
`docker/postgres/init/01-create-test-database.sql` 会创建 `gn_system_test`；已有旧数据卷但缺少测试库时，
请按[测试与数据库隔离指南](docs/development/testing.md)中的非破坏性步骤创建。

```powershell
# 启动或重建
docker compose up --build -d

# 查看服务状态和日志
docker compose ps
docker compose logs -f app nginx queue scheduler

# 数据库迁移
docker compose exec app php artisan migrate

# 生成可重复执行的 Phase 2 本地模拟数据
docker compose exec app php artisan db:seed --class=PhaseTwoDemoDataSeeder

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
站内提醒始终可用；只有设置 `DINGTALK_ENABLED=true` 并提供
`DINGTALK_WEBHOOK_URL`、`DINGTALK_SECRET` 后才会发送钉钉机器人通知。
开发环境用户邀请使用 `MAIL_MAILER=log`，密码设置链接写入应用日志；若需实际收件，
应改用开发专用 SMTP。查询 Excel 和看板 PDF/HTML 导出均写入
`storage/app/private/reports`，必须由 Queue 与 Scheduler 容器共同保障生成和
24 小时过期清理，不能把该目录作为公开 Web 目录。
开发 Compose 将 storage 和 bootstrap/cache 放入 Docker 命名卷，避免 Windows
源码挂载的文件时间和属主语义影响 Blade 缓存、私有导出及队列任务。

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

第一次连接服务器或使用 Codex 排障时先读
[小白运维指南](docs/operations/beginner-operations-guide.md)。服务器准备、环境变量、
发布、回退、异机同步和恢复演练见
[完整运维手册](docs/operations/operations-manual.md)；Production 首次部署和恢复
原则见[局域网生产部署与恢复](docs/operations/production-deployment.md)；版本从
开发、UAT 验收到正式镜像晋级的完整步骤见
[UAT 测试版本与正式发布流程](docs/operations/release-management.md)。不要把
`.env.production`、`.env.uat`、TLS 私钥或真实凭据提交到 Git。

## 模块边界

业务代码按以下十个命名空间组织：

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
├── Audit
└── DataImport
```

共享技术能力放在 `app/Infrastructure`。当前边界测试仅允许 Application 层定向
引用数据所有者模块的 `Application/Contracts` 和 `Application/Data`，继续禁止
跨模块具体实现引用和直接写表。具体规则见
[模块边界文档](docs/architecture/module-boundaries.md)。

## 环境变量与安全

复制 `.env.example` 后只在本地修改 `.env`。不得提交密码、令牌、证书、真实
Sentry DSN、钉钉 Webhook/Secret 或云存储凭据。模板已包含 PostgreSQL、Redis、
日志邮件、备份、Sentry、可选钉钉机器人和可选 S3 配置。

## 分支规范

- `main`：稳定基线
- `develop`：日常集成
- `feature/<topic>`：从 `develop` 创建，验收后合并回 `develop`

CI 在 Pull Request 及推送到 `develop` / `main` 时执行 Composer 校验、Pint、
PHPStan、PHPUnit、前端构建和 Composer / npm 安全审计。

## 项目文档

- [文档导航与权威性说明](docs/README.md)
- [前端页面风格与格式](docs/development/frontend-style-guide.md)
- [当前项目状态](docs/project-status.md)
- [当前架构概览](docs/architecture/overview.md)
- [小白运维指南](docs/operations/beginner-operations-guide.md)
- [完整运维手册](docs/operations/operations-manual.md)
- [CRM 需求文档 v1.9](docs/source/CRM-需求文档-v1.9.md)
- [架构决策记录](docs/adr/README.md)

当前实现状态和后续范围以[项目状态](docs/project-status.md)为准。
