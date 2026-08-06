# GN-System 局域网离线镜像部署

> 适用于当前 UAT，也可作为后续 Production 的基础流程。

GN-System 当前有两条发布路径：

1. 服务器能够稳定访问 GHCR 时，使用标准部署脚本；
2. 服务器访问 GHCR 不稳定时，由办公电脑下载镜像，通过局域网传输到服务器，再在服务器上完成离线部署。

服务器必须部署明确且不可变的版本标签。UAT 使用 `vX.Y.Z-rc.N`，Production 使用
`vX.Y.Z`，禁止使用 `latest`。

## 1. 环境信息

| 环境 | 仓库目录 | 环境文件 | Compose 文件 | Compose 项目 | 地址 |
|---|---|---|---|---|---|
| UAT | `/srv/gn-system/repository` | `.env.uat` | `compose.production.yaml` | `gn-system-uat` | `https://gncrm-uat.local:8443` |
| Production | `/srv/gn-system/production/repository` | `.env.production` | `compose.production.yaml` | `gn-system-production` | `https://gncrm.local` |

本文以 UAT 为例。Production 执行时，将仓库目录、环境文件、URL 和发布状态目录替换为
Production 的独立值，不得共用 UAT 数据、凭据、端口或发布历史。

## 2. 发布前检查

进入 UAT 仓库，不要直接输出完整环境文件：

```bash
cd /srv/gn-system/repository

grep -E \
  '^(COMPOSE_PROJECT_NAME|RELEASE_TAG|APP_URL|DB_DATABASE|RELEASE_STATE_PATH)=' \
  .env.uat

stat -c '%a %n' .env.uat
```

应确认：

```text
COMPOSE_PROJECT_NAME=gn-system-uat
RELEASE_TAG=准备部署的明确版本
APP_URL=https://gncrm-uat.local:8443
DB_DATABASE=gn_system_uat
600 .env.uat
```

权限不是 `600` 时先修正：

```bash
chmod 600 .env.uat
```

验证 Compose 配置和当前服务：

```bash
docker compose \
  --env-file .env.uat \
  -f compose.production.yaml \
  config --quiet

docker compose \
  --env-file .env.uat \
  -f compose.production.yaml \
  ps
```

## 3. 方案 A：服务器直接部署

服务器能够稳定访问 GHCR 时执行：

```bash
cd /srv/gn-system/repository
sudo ./deploy/deploy.sh .env.uat
```

脚本成功时应显示：

```text
Deployment vX.Y.Z completed successfully.
```

标准流程为：配置检查、读取当前和上一版本、完整备份、进入维护模式、停止
Web/Queue/Scheduler、拉取 app/web 镜像、执行数据库迁移、启动服务、生成 Laravel
优化缓存、退出维护模式、检查三个健康接口并写入发布历史。

如果出现以下网络错误：

```text
TLS handshake timeout
connection reset by peer
failed to copy
pkg-containers.githubusercontent.com
```

通常表示服务器访问 GitHub 镜像 CDN 不稳定，不要反复运行部署脚本；改用下面的局域网
离线部署流程。

## 4. 方案 B：办公电脑下载并传输镜像

办公电脑需要 Docker Desktop，并切换到 Linux Containers。以目标版本
`v0.5.0-rc.11` 为例，在 PowerShell 执行：

```powershell
$Tag = "v0.5.0-rc.11"

docker pull --platform linux/amd64 `
  "ghcr.io/moh114514/gn-system-app:$Tag"

docker pull --platform linux/amd64 `
  "ghcr.io/moh114514/gn-system-web:$Tag"

docker images | findstr gn-system
```

`linux/amd64` 必须与 Ubuntu 服务器架构一致。确认 app 和 web 都是目标版本后，将两个
镜像导出到一个 tar 文件：

```powershell
docker save `
  -o ".\docker-images\gn-system-$Tag.tar" `
  "ghcr.io/moh114514/gn-system-app:$Tag" `
  "ghcr.io/moh114514/gn-system-web:$Tag"
```

通过局域网传输到服务器临时目录：

```powershell
scp ".\docker-images\gn-system-$Tag.tar" `
  admin1@192.168.0.141:/tmp/
```

镜像 tar 文件只用于传输，不得提交到 Git。仓库根目录的 `docker-images/` 已被
`.gitignore` 忽略，办公电脑可以把导出的镜像文件集中放在那里。

## 5. 服务器导入并核对镜像

```bash
ssh admin1@192.168.0.141

sudo docker load \
  -i /tmp/gn-system-v0.5.0-rc.11.tar

sudo docker images | grep gn-system
```

核对 app 和 web 的版本及架构：

```bash
sudo docker image inspect \
  ghcr.io/moh114514/gn-system-app:v0.5.0-rc.11 \
  --format '{{.Os}}/{{.Architecture}}'

sudo docker image inspect \
  ghcr.io/moh114514/gn-system-web:v0.5.0-rc.11 \
  --format '{{.Os}}/{{.Architecture}}'
```

两者都应显示：

```text
linux/amd64
```

## 6. 离线部署

当前 `deploy/deploy.sh` 内部固定执行 `docker compose pull`，因此执行 `docker load`
后直接再次运行该脚本仍会访问 GHCR。离线流程在脚本支持离线模式前必须使用手工命令，
并在所有相关 Compose 操作中明确加入 `--pull never`。

### 6.1 尚未运行标准部署脚本

先备份、进入维护模式并停止对外服务和后台任务：

```bash
cd /srv/gn-system/repository

sudo docker compose \
  --env-file .env.uat \
  -f compose.production.yaml \
  exec -T app \
  php artisan backup:run --no-interaction

sudo docker compose \
  --env-file .env.uat \
  -f compose.production.yaml \
  exec -T app \
  php artisan down --retry=30 --no-interaction

sudo docker compose \
  --env-file .env.uat \
  -f compose.production.yaml \
  stop web queue scheduler
```

### 6.2 标准脚本已经在 pull 阶段失败

如果已经看到备份完成、应用进入维护模式、`web`/`queue`/`scheduler` 停止以及随后
GHCR 网络错误，说明备份和维护步骤已经完成，数据库迁移尚未执行。导入目标镜像后，
不要再次备份或重复进入维护模式，直接继续下一节。

### 6.3 迁移、启动和健康检查

```bash
cd /srv/gn-system/repository

sudo docker compose \
  --env-file .env.uat \
  -f compose.production.yaml \
  run --rm --pull never app \
  php artisan migrate --force --isolated --no-interaction

sudo docker compose \
  --env-file .env.uat \
  -f compose.production.yaml \
  up -d --remove-orphans --pull never

sudo docker compose \
  --env-file .env.uat \
  -f compose.production.yaml \
  exec -T app \
  php artisan optimize --no-interaction

sudo docker compose \
  --env-file .env.uat \
  -f compose.production.yaml \
  exec -T app \
  php artisan up --no-interaction
```

## 7. 部署后验收

检查服务、实际镜像和迁移：

```bash
sudo docker compose \
  --env-file .env.uat \
  -f compose.production.yaml \
  ps

sudo docker compose \
  --env-file .env.uat \
  -f compose.production.yaml \
  images

sudo docker compose \
  --env-file .env.uat \
  -f compose.production.yaml \
  exec -T app \
  php artisan migrate:status --no-interaction
```

`web`、`app`、`queue`、`scheduler`、`postgres`、`redis` 都应运行或处于健康状态，
app/web 应为目标版本，所有需要执行的 migration 应显示 `Ran`。

检查三个健康接口：

```bash
curl --fail --silent --show-error \
  https://gncrm-uat.local:8443/up
curl --fail --silent --show-error \
  https://gncrm-uat.local:8443/health
curl --fail --silent --show-error \
  https://gncrm-uat.local:8443/health/operations
```

如果只是自签名证书信任问题，可用 `curl -k` 诊断；`-k` 不得作为正式健康验收标准。
再检查最近日志，并完成登录、管理员权限、客户、代理商、导入、月结、配置中心、邮件、
密码重置和健康页面等业务验收。

```bash
sudo docker compose \
  --env-file .env.uat \
  -f compose.production.yaml \
  logs --tail 200
```

## 8. 写入发布记录和清理传输文件

只有健康检查和业务验收全部通过后，才更新 `current` 和 `history.tsv`：

```bash
cd /srv/gn-system/repository

TAG=$(sed -n 's/^RELEASE_TAG=//p' .env.uat | tail -n 1)
RELEASE_DIR=$(sed -n 's/^RELEASE_STATE_PATH=//p' .env.uat | tail -n 1)
PREVIOUS=$(sudo cat "$RELEASE_DIR/current" 2>/dev/null || echo none)

printf '%s\n' "$TAG" |
  sudo tee "$RELEASE_DIR/current" >/dev/null

printf '%s\t%s\t%s\n' \
  "$(date --iso-8601=seconds)" \
  "$TAG" \
  "$PREVIOUS" |
  sudo tee -a "$RELEASE_DIR/history.tsv" >/dev/null

sudo cat "$RELEASE_DIR/current"
sudo tail -5 "$RELEASE_DIR/history.tsv"
```

确认记录成功后删除服务器上的传输文件：

```bash
sudo rm -f /tmp/gn-system-v0.5.0-rc.11.tar
```

不要立即删除旧版本 Docker 镜像，至少保留当前版本和上一版本以便快速回退。

## 9. 拉取失败后的快速恢复

如果暂时不继续离线部署，且数据库迁移尚未执行，可恢复原版本服务：

```bash
cd /srv/gn-system/repository

sudo docker compose \
  --env-file .env.uat \
  -f compose.production.yaml \
  start web queue scheduler

sudo docker compose \
  --env-file .env.uat \
  -f compose.production.yaml \
  exec -T app \
  php artisan up --no-interaction

sudo docker compose \
  --env-file .env.uat \
  -f compose.production.yaml \
  ps
```

该操作只启动原有容器，不会重新拉取镜像。

## 10. 回退原则和后续脚本改进

如果新版本健康检查失败，只有在数据库迁移向后兼容时，才可以把 `.env.uat` 的
`RELEASE_TAG` 改回上一版本，并使用本地已有镜像执行：

```bash
sudo docker compose \
  --env-file .env.uat \
  -f compose.production.yaml \
  up -d --remove-orphans --pull never
```

如果 migration 不向后兼容，必须停止写入，使用部署前备份恢复数据库和私有文件，再启动
上一版本并完成完整验收，不能只回退镜像。

当前脚本后续可考虑增加 `./deploy/deploy.sh .env.uat --offline` 或
`SKIP_PULL=1 ./deploy/deploy.sh .env.uat`。这属于尚未实现的改进建议；真正实现时还必须
检查本地 app/web 镜像、标签、`linux/amd64` 架构，确保所有 Compose 操作使用
`--pull never`，并在失败时恢复旧服务、仅在健康检查成功后写入发布记录。

日常发布仍优先使用：

```bash
sudo ./deploy/deploy.sh .env.uat
```

GHCR 不稳定时使用：办公电脑拉取镜像 → `docker save` → 局域网传输 → 服务器
`docker load` → 按本文使用 `--pull never` 完成离线部署。
