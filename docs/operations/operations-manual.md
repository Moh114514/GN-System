# GN-System 完整运维手册

> 当前基线：2026-08-04
>
> 适用仓库：`Moh114514/GN-System`
>
> 适用服务器：Ubuntu Server 24.04 LTS x86-64，地址 `192.168.0.141`
>
> 最近已创建的 UAT 候选：`v0.5.0-rc.8`（提交 `8ab9498`）
>
> 当前 `main`：合并提交 `2fe5d13`；包含 RC8 之后的月结历史数据治理变更，尚未自动等同于 UAT 已验收版本
>
> 生产状态：目录已初始化，尚未首次部署

本文是 GN-System 环境、发布、部署和日常运维的统一入口。第一次接触服务器时，先读
[小白运维指南](beginner-operations-guide.md)。版本标签与镜像晋级的细节见
[发布管理手册](release-management.md)，生产首次部署和灾难恢复的原则见
[生产部署与恢复](production-deployment.md)，Phase 5 业务验收见
[Phase 5 UAT 验收手册](phase-five-uat-acceptance.md)。GHCR 访问不稳定时，按
[局域网离线镜像部署](offline-deployment.md)执行。

出现冲突时，按以下顺序确认事实：

1. 目标服务器的只读检查结果；
2. 当前标签对应的代码、Compose、环境模板、脚本和自动化测试；
3. 本手册及其他当前态文档；
4. 早期部署记录和历史规划。

服务器上不得为了匹配旧文档而移动当前 UAT 数据。历史方案
“生产位于 `/srv/gn-system`、UAT 位于 `/srv/gn-system/uat`”已经废弃。

## 1. 系统运行架构

GN-System 是 Laravel 模块化单体，不是微服务。UAT 和生产使用相同的不可变生产
镜像及 `compose.production.yaml`，每套环境包含六个服务：

| Compose 服务 | 职责 | 是否发布宿主端口 |
|---|---|---|
| `web` | Nginx、HTTP 跳转、HTTPS 终止、静态资源 | 是 |
| `app` | PHP-FPM 和 Laravel Web 请求 | 否 |
| `queue` | Redis 队列任务 | 否 |
| `scheduler` | Laravel Scheduler | 否 |
| `postgres` | PostgreSQL 16 主数据库 | 否 |
| `redis` | 缓存、Session 和队列 | 否 |

UAT 当前报告的容器为 `gn-system-uat-web`、`gn-system-uat-app`、
`gn-system-uat-queue`、`gn-system-uat-scheduler`、
`gn-system-uat-postgres` 和 `gn-system-uat-redis`。实际运维应使用 Compose
服务名，不依赖 Docker 自动生成的完整容器名；不同 Compose 版本可能给实例名附加
`-1`。

生产环境不运行 Vite、不挂载仓库源码，不在容器启动时生成 `APP_KEY`，也不自动执行
数据库迁移。app/web 镜像由 GitHub Actions 构建并存入 GHCR；服务器只拉取明确的
不可变版本。

## 2. 环境总览

### 2.1 环境矩阵

| 环境 | 用途 | 代码/制品来源 | 数据要求 | 当前状态 |
|---|---|---|---|---|
| 本地开发 | 编码、调试、自动化测试 | `feature/*` 源码与开发 Compose | 本地模拟或脱敏数据 | 可用 |
| GitHub CI | PR、`develop`、`main` 和标签门禁 | GitHub checkout | 独立 `gn_system_test` | 可用 |
| UAT | 业务验收和 RC 验证 | `vX.Y.Z-rc.N` 对应 GHCR 镜像 | 专用、脱敏验收数据 | 已部署 |
| Production | 正式业务运行 | `vX.Y.Z` 对应已晋级镜像 | 全新生产数据 | 仅初始化 |
| GHCR | 保存 app/web 不可变镜像 | 标签发布工作流 | 不保存业务数据 | 可用 |

GitHub CI 和 GHCR 是发布基础设施，不是可登录的业务环境。

### 2.2 当前服务器目录

```text
/srv/gn-system/                       UAT 根目录
├── repository/                       UAT 独立 Git checkout
├── data/
│   ├── private/                      UAT 私有业务文件
│   └── backups/                      UAT 加密备份
├── releases/                         UAT 当前版本与历史
├── tls/
│   ├── uat-fullchain.pem             UAT 证书
│   └── uat-privkey.pem               UAT 私钥
└── production/                       Production 根目录
    ├── repository/                   Production 独立 Git checkout
    ├── data/
    │   ├── private/                  Production 私有业务文件
    │   └── backups/                  Production 加密备份
    ├── releases/                     Production 当前版本与历史
    └── tls/                           Production 证书和私钥
```

同机部署不代表可以共享资源。两套环境必须保持以下内容全部独立：

- Git checkout 和 detached tag；
- `.env.uat` / `.env.production`；
- Compose project；
- HTTP/HTTPS 端口；
- PostgreSQL、Redis 命名卷；
- `APP_KEY`、数据库密码、Redis 密码、备份密码；
- 私有文件、备份和发布历史；
- TLS 证书、管理员和普通用户；
- SMTP、Sentry、钉钉等外部服务凭据。

### 2.5 最近版本记录与运维影响

以下记录来自当前 `main` 的版本历史。提交记录只能说明代码已经合入，不能替代 UAT
或 Production 的部署、验收和数据库核对。

| 日期/版本 | 提交 | 主要变更 | 运维影响 |
|---|---|---|---|
| 2026-08-03，`v0.5.0-rc.8` | `8ab9498` | 前端界面、实时汇率查看、订单中心和受控状态回退进入最近一个 RC | 这是当前最近的已创建 RC；只能按该 annotated tag 部署，不要用后续 `main` 覆盖其验收范围 |
| 2026-08-03 | `278da6c` | 订单回收站独立为超级管理员页面，支持已删除订单查看与恢复 | 验收时确认普通用户不可读、恢复操作有权限限制和审计，不要用删除代替业务取消 |
| 2026-08-03 | `d405781`、`9c4ef4f` | 增加往期月结周期选择和历史月结入口 | UAT 必须验证历史周期边界、重复生成和不覆盖已有月结 |
| 2026-08-04 | `0971130`、`b2ab309` | 汇率失败保留旧值、历史合作资格、周期边界重建、零订单月结、批次幂等和持久化生成状态 | 涉及审核、历史月结和结算数据；UAT 需准备历史暂停/终止代理商、零订单和报价失败场景 |
| 2026-08-04 | `884f874`、`4aa35d4` | 既有月结状态回填、`unverified` 审计恢复、`not_applicable` 只读门禁 | 必须备份数据库；检查 `000100` 与独立 `000200` migration，核对回填分布和异常记录 |
| 2026-08-04，当前 `main` | `2fe5d13` | 将上述月结治理和恢复流程合入 `main` | 不得直接部署 `main`；应创建下一个递增 RC，完成 CI、镜像、UAT 和 migration 审计后再发布 |

当前 `main` 高于 `v0.5.0-rc.8`。服务器上的 `releases/current` 和
`history.tsv` 才能证明 UAT/Production 实际运行版本；本地 Git 日志不能证明目标环境已经升级。

### 2.3 UAT 当前状态

| 项目 | 当前值 |
|---|---|
| 根目录 | `/srv/gn-system` |
| 仓库目录 | `/srv/gn-system/repository` |
| 环境文件 | `/srv/gn-system/repository/.env.uat` |
| Compose project | `gn-system-uat` |
| 数据库 | `gn_system_uat` |
| PostgreSQL volume | `gn-system-uat_postgres-data` |
| Redis volume | `gn-system-uat_redis-data` |
| HTTPS | `https://gncrm-uat.local:8443` |
| HTTP | `http://gncrm-uat.local:8080` |
| 监听 | `192.168.0.141:8080`、`192.168.0.141:8443` |
| 证书 | `/srv/gn-system/tls/uat-fullchain.pem` |
| 私钥 | `/srv/gn-system/tls/uat-privkey.pem` |
| 备份 | `/srv/gn-system/data/backups` |
| 当前服务状态 | 六个服务均正常 |

Windows 客户端的 hosts 记录为：

```text
192.168.0.141 gncrm-uat.local
```

### 2.4 Production 计划状态

| 项目 | 计划值 |
|---|---|
| 根目录 | `/srv/gn-system/production` |
| 仓库目录 | `/srv/gn-system/production/repository` |
| 环境文件 | `/srv/gn-system/production/repository/.env.production` |
| Compose project | `gn-system-production` |
| 数据库 | `gn_system` |
| PostgreSQL volume | `gn-system-production_postgres-data` |
| Redis volume | `gn-system-production_redis-data` |
| HTTPS | `https://gncrm.local` |
| HTTP/HTTPS 端口 | `80` / `443` |
| 监听地址 | `192.168.0.141` |
| 备份 | `/srv/gn-system/production/data/backups` |
| 当前状态 | 目录已初始化，尚未部署 |

原 `gncrm.local` 证书包含 `DNS:gncrm.local` 和 `IP:192.168.0.141`，计划用于
Production。首次部署前应将证书和私钥安装到
`/srv/gn-system/production/tls/fullchain.pem` 和
`/srv/gn-system/production/tls/privkey.pem`，然后核对证书 SAN、有效期和客户端
信任。Production 必须全新初始化数据库，不复制或迁移 UAT 数据，首个管理员也要
重新创建。

## 3. 环境变量规范

`.env.uat` 和 `.env.production` 被 Git 忽略，只存在于各自仓库目录。权限必须是
`0600`；真实值还要保存在密码管理器或离线恢复材料中。

### 3.1 UAT 必须值

```dotenv
COMPOSE_PROJECT_NAME=gn-system-uat
PRODUCTION_ENV_FILE=.env.uat
RELEASE_STATE_PATH=/srv/gn-system/releases

APP_NAME="GN-System CRM UAT"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://gncrm-uat.local:8443

DB_DATABASE=gn_system_uat
DB_USERNAME=gn_system_uat

PRIVATE_DATA_PATH=/srv/gn-system/data/private
BACKUP_DATA_PATH=/srv/gn-system/data/backups
TLS_CERT_PATH=/srv/gn-system/tls/uat-fullchain.pem
TLS_KEY_PATH=/srv/gn-system/tls/uat-privkey.pem

LAN_BIND_ADDRESS=192.168.0.141
HTTP_PORT=8080
HTTPS_PORT=8443
EXTERNAL_HTTPS_PORT_SUFFIX=:8443

OFFSITE_BACKUP_MONITOR_ENABLED=false
SENTRY_ENVIRONMENT=uat
DINGTALK_ENABLED=false
```

UAT 的 `APP_ENV=production` 表示 Laravel 使用生产运行模式，不表示该环境是正式
业务 Production。业务环境由目录、Compose project、URL、数据、凭据和版本类型共同
区分。

### 3.2 Production 必须值

```dotenv
COMPOSE_PROJECT_NAME=gn-system-production
PRODUCTION_ENV_FILE=.env.production
RELEASE_STATE_PATH=/srv/gn-system/production/releases

APP_NAME="GN-System CRM"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://gncrm.local

DB_DATABASE=gn_system
DB_USERNAME=gn_system

PRIVATE_DATA_PATH=/srv/gn-system/production/data/private
BACKUP_DATA_PATH=/srv/gn-system/production/data/backups
TLS_CERT_PATH=/srv/gn-system/production/tls/fullchain.pem
TLS_KEY_PATH=/srv/gn-system/production/tls/privkey.pem

LAN_BIND_ADDRESS=192.168.0.141
HTTP_PORT=80
HTTPS_PORT=443
EXTERNAL_HTTPS_PORT_SUFFIX=

OFFSITE_BACKUP_MONITOR_ENABLED=true
```

### 3.3 月结自动汇率

月结详情页会按 `SETTLEMENT_EXCHANGE_RATE_PROVIDER` 调用接口盒子汇率服务，成功后预填六位
小数的 CNY → KRW 汇率；审核人仍可手工覆盖。该服务按文章说明每日更新，并非严格实时，页面
应展示报价时间。报价不可用时必须明确提示；已有旧汇率时刷新失败必须标记为“保留旧汇率”，不得显示为成功报价，人工汇率仍可继续审核。UAT 和 Production 必须
分别确认网络出口、服务配额和报价方向。

```dotenv
SETTLEMENT_EXCHANGE_RATE_ENABLED=true
SETTLEMENT_EXCHANGE_RATE_PROVIDER=api_hz
SETTLEMENT_EXCHANGE_RATE_URL=https://cn.apihz.cn/api/jinrong/huilv.php
SETTLEMENT_EXCHANGE_RATE_ID=<接口盒子个人ID>
SETTLEMENT_EXCHANGE_RATE_KEY=<接口盒子个人Key>
SETTLEMENT_EXCHANGE_RATE_TIMEOUT=10
```

请求固定使用 `from=CNY`、`to=KRW`、`money=1`，成功响应需包含 `code=200` 和 `rate`；
`uptime` 作为报价时间保存。ID/Key 只能写入各环境未提交的 `.env`，不得放入仓库。服务不可用时
不要把旧值伪装成实时值；应在页面人工输入并在月结审计中保留人工覆盖标记。

月结中心的“往期月结”可选择最近已关闭的历史周期生成批次。节点按相邻历史配置边界计算，配置切换期间的过渡周期不得被跳过或重叠；参与代理商按周期内合作起止日期判断，不能用当前状态替代历史资格。
如果该周期已有批次，系统保持幂等并返回原批次，同时明确区分新建、处理中、已完成和部分失败，不覆盖已有明细、审核状态或结算文档。

失败批次详情只读取代理商基础身份信息，即使当月资格或政策等级异常，也必须能够打开详情并生成报告；代理商已删除时保留原始 ID 并显示“未知/代理商不存在或已删除”。每次 XLSX 报告使用独立临时文件，下载完成后由响应清理，避免并发下载互相覆盖。一次操作产生的业务规则错误使用红色 Toast，持续阻断状态仍使用页面顶部横幅。

2026-08-04 修复分支已核对本手册涉及的服务器目录、Compose 项目、环境文件、数据库结构、migration、发布、回退和备份流程：本次只改变应用代码与前端交互，不新增 migration、依赖、环境变量或服务器操作；UAT 发布前仍须按第 12 节完成完整门禁，并补验失败详情降级、连续下载和 Toast/横幅行为。

#### 3.3.1 既有月结生成状态迁移

`2026_08_04_000100_add_settlement_generation_state` 不只是新增字段，还会为既有
`settlements` 回填生成状态和真实明细数量：

- 能从系统生成快照、明细、结算文档或有效已完成批次确认的记录标记为 `generated`；零订单但有系统生成快照的记录保留 `item_count=0`，仍可审核；
- `historical_import`/`demo_data` 或带导入批次的历史记录标记为 `not_applicable`，不把导入数据误当成新月结生成；
- 无法可靠确认来源的记录标记为 `unverified`，详情页禁止直接审核。超级管理员必须填写核验依据后选择“核验为历史导入”或“创建恢复批次并重新生成”；两种操作都会记录操作人、修改前后状态和 IP。普通用户和直接调用生成器都不能把 `unverified` 静默改成 `generated`；
- 只有 `pending`/`unverified` 且有有效批次的待审核/已驳回记录可以重新生成；`generated` 和 `not_applicable` 都是不可重生成状态，历史记录保持只读。

该功能由 `2026_08_04_000200_backfill_settlement_generation_state` 独立执行回填。
部署前必须在 UAT 和 Production 分别核对：

```sql
select migration, batch
from migrations
where migration = '2026_08_04_000100_add_settlement_generation_state';
```

如果 `000100` 已经执行，仍必须确认 `000200` 已执行；不能通过重新部署同一个 migration 文件期待旧代码重新运行回填。

在 UAT 或 Production 执行该版本前，必须先完成目标数据库备份并记录备份文件、数据库名、版本、执行人和时间；迁移后核对 `generation_status` 分布、`item_count` 与
`settlement_items` 实际数量一致，并抽查待审核、已驳回、已通过、已结清、零订单和历史导入记录。
如果存在 `unverified`，不得以“迁移成功”作为业务验收通过，必须完成逐条核验或按批准的恢复方案处理。

### 3.4 密钥与外部服务

以下值必须在两套环境分别生成，不能从 UAT 复制到 Production：

- `APP_KEY`；
- `DB_PASSWORD`；
- `REDIS_PASSWORD`；
- `BACKUP_ARCHIVE_PASSWORD`；
- SMTP 凭据；
- Sentry DSN；
- 钉钉 Webhook 和 Secret。

生成 Laravel 所需的 32 字节 base64 密钥时，可以在安全终端执行：

```bash
printf 'base64:%s\n' "$(openssl rand -base64 32)"
```

将整行结果写入 `APP_KEY`。不能只给已有的随机字符串手工添加 `base64:` 前缀。
随机密码可使用：

```bash
openssl rand -base64 48
```

命令输出是敏感信息，不能粘贴到 Issue、PR、聊天记录或 shell 脚本中。

## 4. 从开发到生产的唯一工作流

```text
feature/*
  → PR 和完整 CI
develop
  → 发布 PR 和完整 CI
main
  → annotated RC 标签 vX.Y.Z-rc.N
GitHub Actions
  → 构建并推送 app/web RC 镜像
UAT
  → 人工验收并记录证据
同一提交的正式标签 vX.Y.Z
  → 按原 digest 晋级已验收 RC 镜像
Production
```

### 4.1 分支职责

- `feature/<topic>`：从最新 `develop` 建立的一项独立变更；
- `develop`：日常集成基线；
- `main`：可创建发布标签的稳定代码基线；
- `vX.Y.Z-rc.N`：不可变 UAT 候选；
- `vX.Y.Z`：与已验收 RC 指向同一提交的正式版本。

不得把未完成的活动分支直接合入发布；不得从 `develop` 直接创建 RC；不得把
`latest` 部署到任何服务器。

### 4.2 为什么正式版本不重新构建

RC 标签会执行完整质量门禁并构建 app/web 镜像。UAT 验收的是这些确定的镜像。
正式标签只把同一提交上最新、已验收 RC 的镜像按原 digest 晋级为稳定标签。如果
重新构建，即使源码相同，依赖或构建环境也可能变化，生产运行的就不再是 UAT 验收
过的制品。

## 5. 本地开发与合并

### 5.1 建立功能分支

```bash
git fetch --prune origin
git switch develop
git pull --ff-only origin develop
git status --short
git switch -c feature/<topic>
```

开始前必须确认工作区中没有其他任务的改动。开发环境使用 `compose.yaml` 和 `.env`，
不能拿服务器 `.env.uat` 或 `.env.production` 运行。

### 5.2 启动与验证

Windows PowerShell：

```powershell
Copy-Item .env.example .env
Copy-Item .env.testing.example .env.testing
docker compose up --build -d
docker compose ps
docker compose exec app composer ci:check
docker compose exec vite npm run build
```

只修改文档时至少运行：

```powershell
docker compose exec app composer docs:check
```

如果容器不可用但本机已有 PHP 和 Composer，可以运行等价命令：

```bash
composer docs:check
```

交付时必须写明实际运行了哪条命令，不能把本地检查表述为 UAT 或 Production 已验证。

### 5.3 提交和 PR

```bash
git status --short
git diff
git add <本次任务的明确文件>
git diff --cached
git commit -m "<简短变更说明>"
git push -u origin feature/<topic>
```

功能 PR 目标为 `develop`。准备发布时另建 `develop → main` PR。两个阶段都要等待
GitHub Actions 完整通过；CI 失败必须在分支修复，不能跳过检查或降低规则。

## 6. 创建和晋级版本

以下命令在开发电脑执行，不在服务器执行。示例版本只是格式示范，使用前必须替换为
本次真实版本。

### 6.1 创建 RC

```bash
git fetch --tags --prune origin
git switch main
git pull --ff-only origin main
test -z "$(git status --porcelain)"
test "$(git rev-parse HEAD)" = "$(git rev-parse origin/main)"

RC_TAG=v0.5.0-rc.11
test -z "$(git tag --list "${RC_TAG}")"
git tag -a "${RC_TAG}" -m "GN-System ${RC_TAG} UAT"
git push origin "${RC_TAG}"
```

等待 `release production images` 成功，再检查：

```bash
docker buildx imagetools inspect \
  "ghcr.io/moh114514/gn-system-app:${RC_TAG}"
docker buildx imagetools inspect \
  "ghcr.io/moh114514/gn-system-web:${RC_TAG}"
```

失败的 RC 标签不能删除、移动或复用。修复代码后发布下一个递增 RC。

### 6.2 UAT 通过后创建正式版本

```bash
git fetch --tags --prune origin
git switch main
git pull --ff-only origin main

RC_TAG=v0.5.0-rc.11
STABLE_TAG=v0.5.0
test "$(git rev-parse HEAD)" = "$(git rev-list -n 1 "${RC_TAG}")"
test -z "$(git tag --list "${STABLE_TAG}")"
git tag -a "${STABLE_TAG}" -m "GN-System ${STABLE_TAG}"
git push origin "${STABLE_TAG}"
```

正式标签工作流成功后，检查正式 app/web 镜像 digest 与已验收 RC 一致，再安排
Production 维护窗口。

## 7. 服务器通用准备

### 7.1 必需软件和主机状态

```bash
timedatectl
git --version
docker --version
docker compose version
curl --version
rsync --version
mountpoint --version
sudo systemctl is-enabled docker
sudo systemctl is-active docker
```

服务器时区应为 `Asia/Shanghai`：

```bash
sudo timedatectl set-timezone Asia/Shanghai
```

Docker 应开机自动启动，生产服务由 Compose 的 `restart: unless-stopped` 恢复。主机
重启后仍要人工检查六个服务和三个健康接口。

### 7.2 域名解析

部署脚本会在服务器本机请求 `APP_URL`，因此 Ubuntu 服务器自己也必须能解析 UAT 和
Production 域名。优先配置内网 DNS；尚无内网 DNS 时，在服务器 `/etc/hosts` 中
保留：

```text
192.168.0.141 gncrm-uat.local gncrm.local
```

检查：

```bash
getent hosts gncrm-uat.local
getent hosts gncrm.local
```

Windows 客户端当前至少需要：

```text
192.168.0.141 gncrm-uat.local
```

Production 开放时再加入 `192.168.0.141 gncrm.local`。hosts 只负责名称解析，不会
让证书自动受信任。

### 7.3 GHCR 登录

如果镜像不是公开包，使用只有 `read:packages` 权限的令牌：

```bash
sudo docker login ghcr.io -u Moh114514
```

在交互式密码提示中输入令牌，不要把令牌写进命令行。服务器不需要 GitHub 写权限。

### 7.4 TLS 信任

证书必须覆盖实际访问域名。检查 UAT：

```bash
openssl x509 \
  -in /srv/gn-system/tls/uat-fullchain.pem \
  -noout -subject -issuer -dates -ext subjectAltName
```

检查 Production：

```bash
sudo openssl x509 \
  -in /srv/gn-system/production/tls/fullchain.pem \
  -noout -subject -issuer -dates -ext subjectAltName
```

自建 CA 必须分别加入 Ubuntu 服务器和 Windows 客户端的受信任根证书。部署脚本使用
严格 TLS `curl`，如果服务器本身不信任证书，健康检查会失败。`curl -k` 只能用于
判断“是否仅为证书信任问题”，不能作为部署成功标准。

## 8. UAT 部署

### 8.1 当前 UAT 不要重新初始化

当前 UAT 已在 `/srv/gn-system` 正常运行。不要再次运行主机准备脚本覆盖所有权，
不要重新创建数据库卷，也不要把它移动到 `/srv/gn-system/uat`。更新版本只执行
第 8.2 节。

如未来在一台全新主机重建 UAT，可显式准备根目录：

```bash
sudo /path/to/GN-System/deploy/prepare-host.sh /srv/gn-system
```

随后把独立仓库放在 `/srv/gn-system/repository`，创建 `.env.uat`，安装 UAT
专用证书，并确认第 3.1 节中的路径全部指向 UAT 根目录。

### 8.2 部署新的 RC

先在 GitHub 确认目标 RC 流水线全绿、两个镜像存在。然后登录服务器：

```bash
cd /srv/gn-system/repository
test -z "$(git status --porcelain --untracked-files=no)"
git fetch --tags --prune origin

TARGET_TAG=v0.5.0-rc.11
test "$(git cat-file -t "${TARGET_TAG}")" = tag
git show --no-patch --decorate "${TARGET_TAG}"
git switch --detach "${TARGET_TAG}"
```

`git cat-file` 返回 `tag` 表示它是 annotated tag。确认提交正确后编辑环境文件：

```bash
sudo nano .env.uat
sudo chmod 0600 .env.uat
sudo stat -c '%a %n' .env.uat
```

只把 `RELEASE_TAG` 改为目标 RC，并复核第 3.1 节的固定值。不要重新生成 `APP_KEY`，
否则已有加密数据和 2FA 信息可能无法解密。

部署前只读校验：

```bash
sudo docker compose \
  --env-file .env.uat \
  -f compose.production.yaml \
  config --quiet
```

正式部署：

```bash
sudo ./deploy/deploy.sh .env.uat
```

如果服务器访问 GHCR 不稳定，不要反复运行上面的脚本。当前脚本固定执行
`docker compose pull`，离线镜像导入、失败阶段判断、`--pull never` 迁移/启动和部署后
验收见[局域网离线镜像部署](offline-deployment.md)。

脚本会依次：

1. 拒绝权限不是 `0600` 的环境文件；
2. 拒绝空版本或 `latest`；
3. 校验 Compose；
4. 读取 `releases/current` 中的旧版本；
5. 若 app 正在运行，执行部署前全量备份；
6. 进入维护模式并停止 web、queue、scheduler；
7. 拉取明确版本镜像；
8. 执行 `migrate --force --isolated`；
9. 启动六个服务、优化 Laravel 缓存并退出维护模式；
10. 在 210 秒内等待三个健康接口；
11. 成功后更新 `releases/current` 和 `history.tsv`。

`Nothing to migrate` 表示目标版本没有新的 migration，通常是正常结果；仍需完成健康
和业务验收。

### 8.3 UAT 部署后核对

```bash
cd /srv/gn-system/repository
cat /srv/gn-system/releases/current
tail -n 10 /srv/gn-system/releases/history.tsv

sudo docker compose \
  --env-file .env.uat \
  -f compose.production.yaml ps

curl --fail --show-error --silent \
  https://gncrm-uat.local:8443/up
curl --fail --show-error --silent \
  https://gncrm-uat.local:8443/health
curl --fail --show-error --silent \
  https://gncrm-uat.local:8443/health/operations

curl --head http://gncrm-uat.local:8080
```

HTTP 应跳转到 `https://gncrm-uat.local:8443`。然后至少验收：

- 登录和 TOTP；
- 仪表盘；
- 本次变更涉及的业务页面；
- 创建一个可回收的异步任务，确认 Queue 正常；
- Scheduler 心跳和定时任务；
- 私有下载权限；
- 浏览器控制台和 Network 无新的 5xx。

## 9. Production 部署

### 9.1 首次部署前置条件

以下条件缺一不可：

- UAT 已对目标 RC 验收通过；
- 正式标签与 RC 指向同一提交；
- 正式 app/web 镜像已按相同 digest 晋级；
- `/srv/gn-system/production` 四个子目录已准备；
- 独立 Production 证书、环境文件和密码已就绪；
- SMTP 至少完成一次真实邀请或测试邮件验证；
- 已确定维护窗口、验收负责人和回退负责人；
- Production 数据库保持全新，不导入 UAT 数据。

如果需要重新准备权限：

```bash
cd /srv/gn-system/production/repository
sudo ./deploy/prepare-host.sh /srv/gn-system/production
```

确认不会覆盖已有文件后，再准备独立仓库和环境文件：

```bash
cd /srv/gn-system/production/repository
cp .env.production.example .env.production
sudo chmod 0600 .env.production
sudo nano .env.production
```

### 9.2 首次部署

```bash
cd /srv/gn-system/production/repository
test -z "$(git status --porcelain --untracked-files=no)"
git fetch --tags --prune origin

TARGET_TAG=v0.5.0
test "$(git cat-file -t "${TARGET_TAG}")" = tag
git show --no-patch --decorate "${TARGET_TAG}"
git switch --detach "${TARGET_TAG}"

sudo docker compose \
  --env-file .env.production \
  -f compose.production.yaml \
  config --quiet

sudo ./deploy/deploy.sh .env.production
```

首次启动会在全新的 Production 数据库执行 migration，但不会复制 UAT 数据。完成
后交互式创建 Production 首个超级管理员：

```bash
sudo docker compose \
  --env-file .env.production \
  -f compose.production.yaml \
  exec app php artisan app:create-admin
```

首次登录后立即配置并验证 TOTP。Production 的管理员、密码和 TOTP 不能复用 UAT。

### 9.3 后续正式版本更新

流程与首次部署相同，但不重新复制环境文件、不生成新密钥、不创建管理员。只切换到
目标稳定标签、更新 `.env.production` 的 `RELEASE_TAG`，校验并运行：

```bash
cd /srv/gn-system/production/repository
sudo ./deploy/deploy.sh .env.production
```

生产变更窗口结束前必须完成第 10 节检查，并记录版本、执行人、时间、备份、migration
输出、健康结果和业务冒烟结果。

## 10. 部署验收

### 10.1 Compose 与镜像

UAT：

```bash
cd /srv/gn-system/repository
sudo docker compose --env-file .env.uat \
  -f compose.production.yaml ps
sudo docker compose --env-file .env.uat \
  -f compose.production.yaml images
```

Production：

```bash
cd /srv/gn-system/production/repository
sudo docker compose --env-file .env.production \
  -f compose.production.yaml ps
sudo docker compose --env-file .env.production \
  -f compose.production.yaml images
```

六个服务都应为运行状态，web、app、postgres、redis 的健康检查应通过。Queue 和
Scheduler 是否工作以 `/health/operations` 以及日志为准。

### 10.2 健康接口含义

| 接口 | 验证内容 | 异常含义 |
|---|---|---|
| `/up` | Laravel/PHP 进程存活 | app、PHP-FPM 或 Web 转发异常 |
| `/health` | PostgreSQL 和 Redis 可用 | 数据库、Redis、网络或凭据异常 |
| `/health/operations` | Queue/Scheduler 心跳不超过三分钟 | 队列未消费或调度器未运行 |

Production 检查：

```bash
curl --fail --show-error --silent https://gncrm.local/up
curl --fail --show-error --silent https://gncrm.local/health
curl --fail --show-error --silent https://gncrm.local/health/operations
```

### 10.3 业务冒烟

每次更新至少检查：

1. 超级管理员登录及 TOTP；
2. 首页和本次变更页面；
3. PostgreSQL 读写；
4. Redis Session；
5. 一个队列任务；
6. Scheduler 心跳；
7. 私有报告或文件的授权下载；
8. SMTP/钉钉等本次涉及的外部服务；
9. 应用日志没有连续异常；
10. 备份列表仍可读取。

## 11. 日常运维命令

以下命令不改变业务数据，但 `restart`、`stop`、`up` 会改变服务状态。先进入对应
仓库，并确保环境文件正确。

### 11.1 状态和日志

UAT：

```bash
cd /srv/gn-system/repository
sudo docker compose --env-file .env.uat \
  -f compose.production.yaml ps
sudo docker compose --env-file .env.uat \
  -f compose.production.yaml logs --tail 200
sudo docker compose --env-file .env.uat \
  -f compose.production.yaml logs --tail 200 app web
sudo docker compose --env-file .env.uat \
  -f compose.production.yaml logs --tail 200 queue scheduler
```

Production 只需把目录和环境文件替换为：

```text
/srv/gn-system/production/repository
.env.production
```

持续跟踪日志时使用 `logs --follow --tail 200`，结束查看按 `Ctrl+C`；这不会停止容器。

### 11.2 重启服务

只重启队列和调度：

```bash
sudo docker compose --env-file .env.uat \
  -f compose.production.yaml restart queue scheduler
```

重启应用与 Web：

```bash
sudo docker compose --env-file .env.uat \
  -f compose.production.yaml restart app web
```

不要用 `docker compose down -v`。`-v` 会删除 PostgreSQL 和 Redis 命名卷。

### 11.3 Laravel 检查

```bash
sudo docker compose --env-file .env.uat \
  -f compose.production.yaml exec app php artisan about
sudo docker compose --env-file .env.uat \
  -f compose.production.yaml exec app php artisan migrate:status
sudo docker compose --env-file .env.uat \
  -f compose.production.yaml exec app php artisan schedule:list
sudo docker compose --env-file .env.uat \
  -f compose.production.yaml exec app php artisan backup:list
```

只在明确需要时进入交互 shell：

```bash
sudo docker compose --env-file .env.uat \
  -f compose.production.yaml exec app sh
```

不得在容器内安装 Composer/npm 依赖或修改业务代码。

### 11.4 主机资源

```bash
df -h
free -h
uptime
sudo docker system df
sudo docker stats --no-stream
sudo journalctl -u docker --since "1 hour ago"
```

磁盘不足时先判断占用来源。不得直接运行 `docker system prune -a --volumes`。

### 11.5 安全关机

```bash
sudo shutdown -h now
```

计划重启：

```bash
sudo reboot
```

重启后执行 Compose、三个健康接口、Queue/Scheduler 和备份检查。

## 12. 备份与异机同步

### 12.1 自动策略

Scheduler 按 `Asia/Shanghai` 执行：

| 时间 | 任务 |
|---|---|
| 每小时整点，02:00 除外 | PostgreSQL 数据库备份 |
| 每日 02:00 | PostgreSQL + `storage/app/private` 全量备份 |
| 每日 03:00 | 清理过期备份 |
| 每日 04:00 | 备份健康监控 |
| 每小时 15 分 | Production 异机同步标记监控 |

备份 ZIP 使用 `BACKUP_ARCHIVE_PASSWORD` 加密，并在生成后验证 ZIP 可以打开且包含
文件。保留 7 天内全部备份、30 天内每日最新备份，备份总量上限为 5000 MB；策略
始终保留最新备份。

### 12.2 手工备份与检查

UAT：

```bash
cd /srv/gn-system/repository
sudo docker compose --env-file .env.uat \
  -f compose.production.yaml exec -T app \
  php artisan backup:run --no-interaction
sudo docker compose --env-file .env.uat \
  -f compose.production.yaml exec -T app \
  php artisan backup:list --no-interaction
find /srv/gn-system/data/backups -maxdepth 3 -type f -printf '%TY-%Tm-%Td %TH:%TM %s %p\n'
```

Production：

```bash
cd /srv/gn-system/production/repository
sudo docker compose --env-file .env.production \
  -f compose.production.yaml exec -T app \
  php artisan backup:run --no-interaction
sudo docker compose --env-file .env.production \
  -f compose.production.yaml exec -T app \
  php artisan backup:list --no-interaction
sudo find /srv/gn-system/production/data/backups \
  -maxdepth 3 -type f -printf '%TY-%Tm-%Td %TH:%TM %s %p\n'
```

需要为某个备份生成独立校验记录时：

```bash
sha256sum /完整路径/backup.zip
```

校验值只能证明文件未变化，不能证明数据库和私有文件可以恢复。每月至少在第二套空
环境完成一次实际恢复演练。

### 12.3 Production 异机同步

默认异机挂载点是 `/mnt/gn-system-offsite`。同步脚本先用 `mountpoint` 确认它是
真实挂载文件系统，再验证可写并通过 `rsync --checksum` 同步；挂载失效时会失败，
不会静默写入本机空目录。

安装 Production timer：

```bash
cd /srv/gn-system/production/repository
sudo cp deploy/systemd/gn-system-offsite-backup.* /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now gn-system-offsite-backup.timer
sudo systemctl start gn-system-offsite-backup.service
systemctl list-timers gn-system-offsite-backup.timer
sudo systemctl status gn-system-offsite-backup.service
```

还应将 service 失败状态接入主机监控。UAT 当前
`OFFSITE_BACKUP_MONITOR_ENABLED=false`，不会冒充 Production 异机备份。

## 13. 回退与恢复

### 13.1 判断使用哪种方式

| 情况 | 处理 |
|---|---|
| 新版本健康失败，migration 向后兼容 | 回退旧镜像版本 |
| 新版本健康，业务小缺陷，可等待修复 | 发布下一个版本，不回退数据 |
| 已执行破坏性或不兼容 migration | 停止写入，从部署前备份恢复 |
| 数据误删、损坏或 APP_KEY 丢失 | 灾难恢复；不要只换镜像 |

不能仅凭“页面打不开”直接恢复数据库。先保存日志、容器状态、版本和健康接口证据。

### 13.2 只回退应用版本

查看版本历史：

```bash
cat /srv/gn-system/releases/current
tail -n 20 /srv/gn-system/releases/history.tsv
```

Production 对应路径为 `/srv/gn-system/production/releases`。人工确认一个确实存在的
旧标签后：

1. 把环境文件的 `RELEASE_TAG` 改为旧标签；
2. 将仓库 detached checkout 切换到同一标签；
3. 确认 migration 向后兼容；
4. 再运行 `deploy/deploy.sh`；
5. 完成全部健康和业务验收。

不要自动取历史文件最后一列后立即部署；必须先确认标签、镜像和数据库兼容性。

### 13.3 从备份恢复

`deploy/restore.sh` 只允许恢复到没有任何 public 表的空数据库，并要求人工输入
`RESTORE`。它不是在现有生产库上覆盖恢复的快捷命令。

Production 空环境示例：

```bash
cd /srv/gn-system/production/repository
sudo ./deploy/restore.sh \
  .env.production \
  /srv/gn-system/production/data/backups/<backup>.zip
```

脚本会：

1. 验证备份存在；
2. 拒绝非空目标数据库；
3. 交互读取备份密码；
4. 解密并提取 ZIP；
5. 查找 PostgreSQL SQL dump；
6. 停止 web、queue、scheduler、app；
7. 导入数据库；
8. 用备份私有目录同步目标目录；
9. 启动服务并检查 migration 状态。

恢复后必须验证：

- 三个健康接口；
- 标签和镜像版本；
- 关键表记录数量及抽样业务数据；
- 私有报告/导入文件；
- 使用加密数据的客户字段；
- 已配置 TOTP 的用户能登录；
- Queue、Scheduler 和备份计划。

初始目标为 RTO 4 小时、数据库 RPO 1 小时。当前没有 WAL/PITR。

## 14. 常见故障排查

### 14.1 浏览器打不开或 `curl` 无输出

按层次检查：

```bash
getent hosts gncrm-uat.local
ip addr show
sudo ss -lntp | grep -E ':(8080|8443)\b'
curl --verbose https://gncrm-uat.local:8443/up
sudo docker compose --env-file .env.uat \
  -f compose.production.yaml ps
```

Windows 先检查：

```powershell
ping gncrm-uat.local
Test-NetConnection gncrm-uat.local -Port 8443
```

如果 `curl -k` 成功而严格 `curl` 失败，问题是证书信任或证书名称，不是应用健康。

### 14.2 证书报警

检查：

- 浏览器访问的域名是否在 SAN 中；
- 证书是否过期；
- UAT 是否误用了 `gncrm.local` 证书；
- Windows 和 Ubuntu 是否信任签发 CA；
- 服务器日期时间是否正确；
- Nginx 实际挂载的证书路径是否与环境文件一致。

不能长期让用户忽略浏览器警告，也不能把私钥发送给客户端。

### 14.3 端口或环境冲突

```bash
sudo ss -lntp | grep -E ':(80|443|8080|8443)\b'
sudo docker ps --format 'table {{.Names}}\t{{.Ports}}\t{{.Image}}'
```

UAT 只能占用 8080/8443，Production 只能占用 80/443。若 Compose project 名错误，
可能生成第三套意外的 PostgreSQL/Redis volume；发现后先停止操作并核对数据归属，
不要直接删除 volume。

### 14.4 `.env` 被拒绝

部署脚本要求权限恰好为 `0600`：

```bash
sudo chmod 0600 .env.uat
sudo stat -c '%a %U:%G %n' .env.uat
```

同时检查 `PRODUCTION_ENV_FILE` 与实际文件名一致，变量行不要在 `=` 两侧加空格。

### 14.5 `APP_KEY` 错误

症状可能包括启动异常、Session 失效、加密字段或 TOTP 无法解密。检查是否是
`base64:` 加上有效 32 字节密钥。已运行环境不能随意更换 `APP_KEY`。如果密钥丢失，
备份数据库本身也不能恢复原有加密数据。

### 14.6 GHCR 拉取失败

```bash
sudo docker login ghcr.io -u Moh114514
sudo docker pull ghcr.io/moh114514/gn-system-app:<明确标签>
sudo docker pull ghcr.io/moh114514/gn-system-web:<明确标签>
```

检查标签是否由成功的发布工作流生成、令牌是否有 `read:packages`、服务器时间和 DNS
是否正常。不要改为 `latest` 绕过。若确认是 GHCR/CDN 网络不稳定，停止重复重试，按
[局域网离线镜像部署](offline-deployment.md)从办公电脑下载、传输和导入同一明确版本。

### 14.7 app、PostgreSQL 或 Redis unhealthy

```bash
sudo docker compose --env-file .env.uat \
  -f compose.production.yaml logs --tail 200 app postgres redis
sudo docker compose --env-file .env.uat \
  -f compose.production.yaml exec postgres \
  pg_isready -U gn_system_uat -d gn_system_uat
sudo docker compose --env-file .env.uat \
  -f compose.production.yaml exec redis \
  sh -ec 'redis-cli --no-auth-warning -a "$REDIS_PASSWORD" ping'
```

不要把数据库或 Redis 端口暴露到宿主机作为排障手段。

### 14.8 `/health/operations` 返回 503

```bash
sudo docker compose --env-file .env.uat \
  -f compose.production.yaml ps queue scheduler
sudo docker compose --env-file .env.uat \
  -f compose.production.yaml logs --tail 200 queue scheduler
```

短暂启动后要等待心跳刷新；持续超过三分钟不健康才是实际故障。确认 Redis、队列和
Scheduler 都在运行，不要只重启 Web。

### 14.9 备份告警

```bash
sudo docker compose --env-file .env.production \
  -f compose.production.yaml exec -T app \
  php artisan backup:list --no-interaction
sudo systemctl status gn-system-offsite-backup.service
sudo journalctl -u gn-system-offsite-backup.service --since "24 hours ago"
mountpoint /mnt/gn-system-offsite
```

备份失败邮件需要处理，不能只删除邮件。若异机挂载失效，先恢复挂载，再手工触发同步
并检查 `.offsite-sync-success` 的更新时间。

### 14.10 PDF 中文字体或私有目录权限

生产镜像应包含已在 CI 验证的 PDF 字体。若导出失败：

```bash
sudo docker compose --env-file .env.uat \
  -f compose.production.yaml logs --tail 200 app queue
namei -l /srv/gn-system/data/private
```

确认 app、queue、scheduler 对 `PRIVATE_DATA_PATH` 可读写；不要把私有目录改为
`0777`，也不要挂载到 Nginx 公开目录。

## 15. 安全边界与禁止事项

- 禁止在服务器直接编辑 `app/`、`resources/`、`routes/` 等源码；
- 禁止服务器执行 `composer install`、`npm install` 或 `docker build`；
- 禁止运行 `git pull` 后直接部署，必须切换到明确 detached tag；
- 禁止部署 `latest`、分支名或未发布提交；
- 禁止删除、移动或复用已推送标签；
- 禁止 UAT 和 Production 共用数据库、Redis、volume、目录、凭据或管理员；
- 禁止把 UAT 数据迁入 Production；
- 禁止提交 `.env`、TLS 私钥、真实令牌和备份密码；
- 禁止执行 `docker compose down -v`；
- 禁止未经确认运行 `migrate:fresh`、`db:wipe` 或直接删表；
- 禁止只回退镜像而忽略不兼容 migration；
- 禁止使用 `curl -k` 作为上线验收；
- 禁止把普通用户随意加入 `docker` 组；Docker 权限等同于主机 root 权限；
- 禁止在没有实际恢复演练时宣称备份可用。

## 16. 检查清单

### 16.1 RC 发布

- [ ] 所有已完成业务分支已通过 PR 合入 `develop`
- [ ] 未验证的 Dependabot 或其他活动分支未混入
- [ ] `develop → main` 发布 PR 完整 CI 全绿
- [ ] 本地 `main` 与 `origin/main` 一致
- [ ] RC 是递增、未使用的 annotated tag
- [ ] 标签指向目标 `main` 提交
- [ ] 标签工作流全绿
- [ ] app/web RC 镜像均存在

### 16.2 UAT 更新

- [ ] 目标 RC 与已检查镜像一致
- [ ] UAT Git 工作区没有受跟踪改动
- [ ] `.env.uat` 权限为 `0600`
- [ ] 路径、端口、URL、证书仍为 UAT 值
- [ ] `RELEASE_TAG` 已改为目标 RC
- [ ] Compose `config --quiet` 通过
- [ ] 部署前备份成功
- [ ] 六个服务运行
- [ ] 三个健康接口成功
- [ ] HTTP 跳转到带 `:8443` 的 HTTPS
- [ ] 本次业务功能通过验收
- [ ] 记录 RC、提交、镜像摘要和证据

GHCR 网络不稳定时，另确认：

- [ ] 办公电脑使用 `linux/amd64` 拉取 app/web 目标镜像
- [ ] tar 文件通过局域网传输并在服务器 `docker load` 成功
- [ ] 服务器 app/web 镜像标签和架构与 `RELEASE_TAG` 一致
- [ ] 离线 Compose 操作使用 `--pull never`
- [ ] 健康检查成功后才写入 `releases/current` 和 `history.tsv`

### 16.3 Production 首次部署或更新

- [ ] UAT 验收正式通过
- [ ] 正式标签与 RC 指向同一提交
- [ ] 正式镜像 digest 与 RC 一致
- [ ] Production 独立目录、环境文件、证书和密码已核对
- [ ] Production 数据库不含 UAT 数据
- [ ] 维护和回退负责人已到位
- [ ] 部署前备份或首次部署空库状态已确认
- [ ] migration 输出已记录
- [ ] 六个服务和三个健康接口成功
- [ ] 登录、TOTP、核心业务、Queue、Scheduler、私有下载通过
- [ ] SMTP、备份和异机同步按上线范围验证
- [ ] 发布结果已记录

### 16.4 每日/每周例行

- [ ] 六个服务正常
- [ ] 三个健康接口正常
- [ ] 最近备份时间和大小合理
- [ ] 磁盘、内存和 Docker 占用无异常
- [ ] 没有持续的 5xx、队列或 Scheduler 错误
- [ ] Production 异机同步标记未过期
- [ ] 证书到期时间仍有足够余量

## 17. 文档维护

系统状态发生变化时，必须同时核对并更新本手册和
[小白运维指南](beginner-operations-guide.md)。这里的“系统状态”包括当前阶段、已实现
能力、UAT/Production 部署状态、正在运行的版本、环境地址、已知运维问题和恢复结论等。
两份文档虽然面向不同读者，但相关事实必须保持一致；若某次状态变化不影响其中一份，
也要在 Pull Request 中写明已完成核对。

路径、域名、端口、Compose project、证书、环境文件或发布流程变化时，必须同时更新：

- 本手册；
- [小白运维指南](beginner-operations-guide.md)；
- `AGENTS.md`；
- `.env.uat.example` / `.env.production.example`；
- 发布和生产部署手册；
- 相关 Compose 校验；
- 涉及的 systemd 运维模板。

服务器现状与仓库文档不一致时，先只读核对实际状态，再通过正常开发分支修正文档和
模板。不得在服务器临时改脚本后把差异留在仓库之外。
## PR-C UAT operations

The UAT reset and configuration reload are host operations. Run them from the UAT repository `/srv/gn-system/repository` only after checking the target environment. The UAT root remains `/srv/gn-system`; reports and private data stay under that root:

```bash
cd /srv/gn-system/repository
./deploy/reset-uat.sh --business-data
./deploy/reload-config.sh uat
```

`--business-data` creates a database backup, stops queue and scheduler, invokes `app:reset-uat-data`, removes only the approved private `imports`, `reports`, and `settlements` directories, flushes only the UAT Redis container, restores services, and checks `/up`, `/health`, and `/health/operations`. The checks use `TLS_CERT_PATH`, seed queue/scheduler heartbeats, and retry for up to 180 seconds. It preserves users, institutions, reference configuration, saved queries, and migrations. The application command verifies `APP_ENV`, UAT `APP_URL`, configured PostgreSQL, `current_database()`, and the private storage root before truncating the approved business tables.

UAT reset audit events are written by phase after the database transaction so they are not removed when `activity_log` is reset: `database_reset_completed`, `private_files_cleanup_completed`, and `reset_completed`. A failure records `reset_failed` with the failing phase when the audit backend remains available.

Use `./deploy/reset-uat.sh --full` only for explicit UAT initialization. It requires the exact interactive phrase `RESET gn_system_uat`, creates a full backup, rebuilds only the UAT database, migrates, restores reference data, and interactively creates an administrator. The script rejects the production directory, the `gn_system` database, a non-UAT URL, and a different Compose project. Never mount the Docker socket into the application container and never use `docker compose down -v`.

Administrator maintenance is non-destructive:

```bash
docker compose --env-file .env.uat -f compose.production.yaml exec app php artisan app:list-admins
docker compose --env-file .env.uat -f compose.production.yaml exec app php artisan app:disable-admin ADMIN --reason="reason" --operator="operator-or-ticket"
docker compose --env-file .env.uat -f compose.production.yaml exec app php artisan app:enable-admin ADMIN --reason="reason" --operator="operator-or-ticket"
docker compose --env-file .env.uat -f compose.production.yaml exec app php artisan app:reset-admin-password ADMIN --reason="reason" --operator="operator-or-ticket" --clear-2fa
```

`ADMIN` is an ID or email. `--operator` is a required operator or ticket identifier for audit traceability. Passwords are entered interactively, never passed as arguments. Disabling and password reset increment `session_version` and clear existing sessions; disabling the last active super administrator is rejected. There is intentionally no physical delete command.

`reload-config.sh uat` requires `.env.uat` mode `0600`, validates required variables and Compose configuration, force-recreates app/queue/scheduler, rebuilds Laravel configuration cache, verifies PostgreSQL/Redis and the three health endpoints, and prints only a sanitized summary. It never prints password, archive-password, mail, or webhook-secret values.
