# GN-System CRM

## 2026-10-31 v0.8.0-rc.3 已构建，UAT 部署待管理员认证

PR #67、#69 已合入 `develop`，PR #68 已合入 `main`。不可变 annotated 标签
`v0.8.0-rc.3` 指向 `ab1cb51eef90347c90d38ee4a6cb44b16e1da931`；[RC 发布工作流](https://github.com/Moh114514/GN-System/actions/runs/41083389896)
门禁与 app/web 构建成功，manifest digest 为 `sha256:be51649457712b42030e8f707306c0f91d136fb35f43b8a5d083b96954fe3dc6`、
`sha256:416c3c73a9cee5cebfe326012d9ceeaa7f83d0b30fa9882772fd34d9f1f65298`。

本机离线包 `docker-images/gn-system-v0.8.0-rc.3.tar` 为 369,907,200 bytes，SHA-256
`43bd4157c777353b8fa6b1aa9f101ac850567722b45176f866746208c6acdba7`；已传至 UAT `/tmp/gn-system-v0.8.0-rc.3.tar`，
服务器副本哈希及大小一致。UAT 仓库只读核验仍为干净的 detached RC2 提交 `ce411b7700509a4322752c40c786f94b6adc9ddb`；
本轮 `sudo -n` 要求管理员密码，故 RC3 尚未导入、备份、部署或迁移，UAT 仍待部署和重新验收。Production 未操作。
Production URL `APP_URL` 与手册域名不一致，证书和备份也未核实；正式发布仍须补齐这些检查，并以已验收 RC3 原 digest 晋级。

## 2026-10-30 v0.8.0-rc.2 UAT 验收通过，Production 晋级暂缓

PR #63 已将修复合入 `develop`，PR #65 同步 main 发布历史，PR #64 合入 `main`。
annotated `v0.8.0-rc.2` 指向 `ce411b7700509a4322752c40c786f94b6adc9ddb`；
[RC 发布工作流](https://github.com/Moh114514/GN-System/actions/runs/41039604852)通过应用检查和 app/web 镜像构建。
app/web digest 分别为 `sha256:8f1aa5c898f64d69d37edd71156ae51dd218e4c2d55709dbee7524a57531640c`、
`sha256:681d417d1f08d02204185df105cb8202a975d9d481d47fc2fe0767d467b4d5b4`。

已按[离线指南](docs/operations/offline-deployment.md)下载、核对并通过局域网部署。
部署包 `D:\GN-System-Deployment\gn-system-v0.8.0-rc.2.tar` 为 369,906,176 bytes，SHA-256 为
`434064174a257b0979334384a17674da96580f1b85fd7d7f092bb5df3539628c`；服务器副本曾在
`/tmp/gn-system-v0.8.0-rc.2.tar` 完成校验和导入，并在验收后清理。部署前备份
`/srv/gn-system/data/backups/GN-System UAT/2026-10-30-18-41-50.zip` 为 71,325,256 bytes，
257 个文件/目录，完整性验证通过。

服务器 `.env.uat` 为 `RELEASE_TAG=v0.8.0-rc.2`、权限 `0600`，数据库为 `gn_system_uat`。
迁移命令结果为 `Nothing to migrate`；所有迁移状态均为 `Ran`。六个服务运行，app/web/queue/scheduler
使用 RC2；`/up`、`/health`、`/health/operations` 严格 TLS 均为 200，队列和调度状态为 ok。
2026-10-30 22:28（Asia/Shanghai）用户确认 RC2 业务验收通过；UAT `releases/current` 与 `history.tsv`
已记录 `v0.8.0-rc.2`，服务器传输包已按离线指南清理，旧镜像保留。

Production 预检发现当前服务运行 `v0.6.2`，但独立仓库仍停在 `v0.6.0-rc.1` 且有 3 个未提交文件。
其中客户看板翻译和测试已包含在 RC2；`AppServiceProvider` 中的生产 URL origin 修正未包含在 RC2，已在新分支
`feature/production-origin-url` 纳入代码评审并补充回归测试。包含该修正的 `v0.8.0-rc.3` 已发布，
当前等待 UAT 部署和重新验收；正式标签 `v0.8.0` 尚未创建，Production 未修改或部署。

Production `.env.production` 当前为 `RELEASE_TAG=v0.6.2`、`APP_URL=https://192.168.0.141`，与运维文档中的
`https://gncrm.local` 不同；Production `current/history.tsv` 缺失，证书身份及生产备份完整性尚未验证。
这些项与未提交源码一起，都是恢复正式部署前必须核实的事项。

## 2026-10-30 v0.8.0-rc.1 离线部署与业务验收记录（RC2 部署前）

PR #57、#58 已依次合入 `develop`、`main`。annotated `v0.8.0-rc.1` 固定指向
`3e0d9f935c4756b845fe4e931fe53b591c0bb14c`；[RC 工作流](https://github.com/Moh114514/GN-System/actions/runs/40855469756)
通过 596 项测试、4261 条断言及镜像构建。app/web 摘要分别为
`sha256:7c86fa42b70410868d789cb8bb985ff9e4152cec5405358eaa11b5f804b76a10`、
`sha256:87f1cd9e91b1e697cbaf38d7e0b6a2f0f74f7034051c53fded073ab592d03dbf`。

2026-10-30 已按离线指南在本机下载并核对镜像，导出到 D 盘后通过局域网传输、校验并导入 UAT。
重新生成的全量备份通过归档完整性检查；三项候选 migration 均为 batch 12 / Ran。六个服务运行，
app/web/queue/scheduler 使用候选镜像，三个严格 TLS 健康接口均返回 200，队列和调度心跳正常。
实际数据库为 `gn_system_uat`，BusinessClock 为真实时间；`.env.uat` 为候选版本且权限 `0600`。

历史数据缺口已记录，未推算回写。2026-10-30 独立超管浏览器实测通过凭证二选一、19 个主要页面、TOTP 登录、
凭证下载授权及 BD/两位客服的权限抽查；老板/运营总监、Excel 回传、导入和月结完整流程仍待验收。按离线指南，
`releases/current` 和 `history.tsv` 暂保留原发布标记 `v0.6.0-rc.1`，当前运行镜像已是候选版本。
Production 未操作。2026-10-29 的 GHCR 下载失败已通过离线转运解决；10 月 22 日历史验收说明与服务器
发布历史的差异仍待核对。该次发布时本机 hook 无法访问 Docker，曾使用一次性 bypass，远程 CI 已复核。

备份、历史异常和待验项目见[完整运维手册](docs/operations/operations-manual.md)及
[离线镜像部署指南](docs/operations/offline-deployment.md)。
后续[历史与新增联合验收](docs/operations/business-flow-uat-review.md)通过了部分服务层计算场景，但确认历史导入与新增业务仍有衔接缺口；
原表预检失败未正式提交；服务夹具已回滚，后续网页独立样本与审计保留。跨月登记及已付款更正通过，
但当期历史月结衔接、历史状态映射和终止合作页面仍有优先问题，未标记全部验收通过。

## 2026-10-22 正式版本 v0.7.0

用户确认服务器 UAT 已验收通过；annotated `v0.7.0` 已推送，与 `v0.7.0-rc.1` 均指向
`4d81dca1685eb9fb324ba0d0d71d48d8356eb4d4`，并与发布时的 `origin/main` 一致。
[正式发布流水线](https://github.com/Moh114514/GN-System/actions/runs/39781680735)成功，app/web
按已验收 RC 的原 manifest/digest 晋级，已独立读取两组镜像核对一致，未重新构建。
标签推送共享 hook 完整通过 `composer ci:check`（579 项测试/3928 断言）与前端构建。
两份运维指南已同步正式镜像摘要、生产升级前置条件和恢复边界；Agent 本轮未连接或部署生产服务器。
生产升级步骤见[完整运维手册](docs/operations/operations-manual.md)。下方日期记录保留对应阶段当时状态。

## 2026-10-22 UAT 候选版本 v0.7.0-rc.1

订单登记与月结闭环及审查修复经 PR #52、#53 合入 `main`；annotated `v0.7.0-rc.1` 固定指向
`4d81dca1685eb9fb324ba0d0d71d48d8356eb4d4`，创建时已核对与 `origin/main` 一致。
[RC 发布流水线](https://github.com/Moh114514/GN-System/actions/runs/39776845868)记录门禁、app/web 构建及发布结果；
部署前须确认该流水线成功，并核对 GHCR 上的两个镜像摘要。Vite 已定向升级至 8.1.6，PHP/npm 审计通过。
本机完整门禁和标签共享 hook 通过 579 项测试/3928 断言及前端构建。UAT/Production 本轮未部署、迁移或人工验收；
升级前备份、历史到院资料核对与恢复边界见[完整运维手册](docs/operations/operations-manual.md)。
后续文档提交不移动此标签；正式版本必须指向本 RC 的同一提交并按原镜像 digest 晋级。

## 2026-10-15 正式制品发布

annotated `v0.6.2` 已发布，与 `v0.6.2-rc.1` 均指向提交
`20275f605b5a5e9629bc9b295e237566b0e6f0df`。[RC 工作流](https://github.com/Moh114514/GN-System/actions/runs/38745069895)
和[正式发布工作流](https://github.com/Moh114514/GN-System/actions/runs/38749794915)均成功，app/web
按原 digest 晋级且已独立核对一致。客户档案可选字段、订单凭证、团队客户入口、看板文案及 Symfony
安全补丁已纳入本版。用户确认业务 UAT 已验收通过；Agent 未连接服务器复核，生产部署由用户执行。
此前 `v0.6.1` 因正式与 RC 提交不一致而失败，旧标签保留，不应部署该缺失镜像的版本。
镜像 digest、部署前拉取和回退要求见[完整运维手册](docs/operations/operations-manual.md)。
下方日期记录描述对应日期当时状态。

GN-System 是面向医美/医疗代理业务的内部客户管理系统，用于逐步替代分散的
Excel 客户、代理商、订单和结算数据。当前已完成 Phase 1 基础架构、Phase 2
核心数据与导入能力、Phase 3 客户全生命周期及 Phase 4 代理商与推广费核算核心
闭环、Phase 5 月结、结算单与主动提醒中心，以及 Phase 6 多维查询、真实数据看板
和配置中心，并已启用独立订单中心。角色/业务组、权限范围、负责人移交、机构回传订单事实、
BD 季度提成及机构月度销售额总览已进入版本历史。2026-10-07 发布准备时，远程最新正式
标签为 `v0.5.3`（`9001f7b`），对应 RC 为 `v0.5.3-rc.1`；标签不代表服务器已部署或验收。
2026-10-09，直客业务线及管理员渠道维护已通过 PR #41 合入 `develop`，通过 PR #42 合入
`main`；annotated 候选标签 `v0.6.0-rc.1` 指向发布提交 `290a368`。镜像状态和 digest 以
[RC 工作流](https://github.com/Moh114514/GN-System/actions/runs/37924051352)及 GHCR 为准。
正式 `v0.6.0` 尚未创建，须等 UAT 验收通过后按同一提交和镜像 digest 晋级。服务器部署
暂缓，UAT/Production 实际版本、迁移及验收状态待核验。真实历史数据迁移仍待正式
源文件、错误处理和抽样核对。

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
docker compose build app
docker compose up --no-build -d
```

app、queue 和 scheduler 共用同一开发镜像，因此只构建 app，再启动全部服务；
避免新版本 Docker/BuildKit 并行导出同名镜像时发生冲突。此步骤仅适用于本地开发，
UAT/Production 继续使用标签工作流构建的 app/web 镜像和标准发布脚本。

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
docker compose build app
docker compose up --no-build -d

# 查看服务状态和日志
docker compose ps
docker compose logs -f app nginx queue scheduler

# 数据库迁移
docker compose exec app php artisan migrate

# 生成完整、可重复执行的本地开发场景数据
docker compose exec app php artisan db:seed

# 也可以显式执行开发场景 Seeder
docker compose exec app php artisan db:seed --class=DevelopmentScenarioSeeder

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
本地和 UAT 模板默认开启 `APP_IMPERSONATION_ENABLED`，允许超级管理员在网页中以启用且已接受邀请的 BD/客服用户身份测试权限；Production 模板明确关闭，代码还会按 `APP_DEPLOYMENT_ENV=production` 硬关闭。该功能只保存会话中的真实账号和目标账号 ID，不修改任何用户角色或权限数据。

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
- [PR7 UAT 迁移与发布收尾手册](docs/operations/pr7-uat-migration-runbook.md)

当前实现状态和后续范围以[项目状态](docs/project-status.md)为准。
