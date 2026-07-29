# UAT 测试版本与正式发布流程

本文规定 GN-System 从开发提交到 UAT 验收、再到正式生产的唯一发布路径：

`feature/* → develop → main → RC 标签 → GitHub Actions → GHCR → UAT → 正式标签 → Production`

UAT 和生产使用同一套不可变生产镜像。服务器不挂载或修改应用源码，也不安装 Composer
或 npm 依赖。

## 1. 日常开发

从最新 `develop` 创建功能分支：

```bash
git switch develop
git pull --ff-only origin develop
git switch -c feature/<topic>
```

提交前检查实际改动，只暂存本次任务文件：

```bash
git status
git diff
git add <明确的文件路径>
git diff --cached
git commit -m "<变更说明>"
git push -u origin feature/<topic>
```

通过 Pull Request 合入 `develop`。准备 UAT 时，再通过 Pull Request 将 `develop`
合入 `main`，等待完整 CI 通过。不要把不加检查的 `git add .` 或裸 `git push`
作为默认发布步骤。

## 2. 创建 UAT RC

在开发电脑同步远端 `main`，并确认工作区、提交和标签名：

```bash
git fetch --prune origin
git switch main
git pull --ff-only origin main
git status --short
git rev-parse HEAD
git rev-parse origin/main
git tag --list 'v*-rc.*' --sort=version:refname
```

只有工作区干净且 `HEAD` 与 `origin/main` 完全一致时才创建下一个 annotated RC：

```bash
test -z "$(git status --porcelain)"
test "$(git rev-parse HEAD)" = "$(git rev-parse origin/main)"
test -z "$(git tag --list v0.5.0-rc.3)"
git tag -a v0.5.0-rc.3 -m "GN-System v0.5.0-rc.3 UAT"
git push origin v0.5.0-rc.3
```

标签必须递增且不可变。等待 GitHub Actions 的 `release production images` 成功，
并确认以下两个镜像标签都能读取：

```bash
docker buildx imagetools inspect ghcr.io/moh114514/gn-system-app:v0.5.0-rc.3
docker buildx imagetools inspect ghcr.io/moh114514/gn-system-web:v0.5.0-rc.3
```

如果门禁、构建或推送失败，不得删除、移动或重新推送原标签。修复仍通过
`feature/* → develop → main`，然后发布 `v0.5.0-rc.4`。

## 3. 同机 UAT 首次准备

生产继续使用 `/srv/gn-system`；UAT 使用 `/srv/gn-system/uat`。在现有生产仓库
执行主机目录准备脚本，再克隆一份独立仓库：

```bash
cd /srv/gn-system/repository
sudo ./deploy/prepare-host.sh /srv/gn-system/uat
git clone https://github.com/Moh114514/GN-System.git \
  /srv/gn-system/uat/repository
cd /srv/gn-system/uat/repository
cp .env.uat.example .env.uat
chmod 0600 .env.uat
```

把 UAT 证书和私钥安装到 `/srv/gn-system/uat/tls`。填写 `.env.uat` 时必须使用：

- `COMPOSE_PROJECT_NAME=gn-system-uat`
- `PRODUCTION_ENV_FILE=.env.uat`
- UAT 专用 `APP_KEY`、数据库/Redis/备份密码和测试域名
- UAT 专用数据、备份、TLS 和发布历史路径
- `HTTP_PORT=8080`、`HTTPS_PORT=8443`、
  `EXTERNAL_HTTPS_PORT_SUFFIX=:8443`
- `APP_ENV=production`、`APP_DEBUG=false`
- `OFFSITE_BACKUP_MONITOR_ENABLED=false`
- 沙箱邮件/Sentry；`DINGTALK_ENABLED=false`，不得复制生产通知凭据

UAT 只能使用脱敏或专用验收数据。服务器使用只读 GHCR 凭据：

```bash
docker login ghcr.io -u Moh114514
```

## 4. 更新 UAT

UAT 只切换明确标签，不使用 `git pull`：

```bash
cd /srv/gn-system/uat/repository
test -z "$(git status --porcelain --untracked-files=no)"
git fetch --tags --prune origin
git show --no-patch v0.5.0-rc.3
git switch --detach v0.5.0-rc.3
sudoedit .env.uat
chmod 0600 .env.uat
sudo ./deploy/deploy.sh .env.uat
```

在 `.env.uat` 中只把 `RELEASE_TAG` 改为已经成功发布的 RC。部署完成后核对：

```bash
cat /srv/gn-system/uat/releases/current
curl --fail https://uat.crm.example.com:8443/up
curl --fail https://uat.crm.example.com:8443/health
curl --fail https://uat.crm.example.com:8443/health/operations
```

还应验证 `http://uat.crm.example.com:8080` 跳转到
`https://uat.crm.example.com:8443`，并完成业务验收、队列、调度和通知沙箱检查。
Phase 5 的数据准备、导入、月结、结算单、提醒与证据记录按
[Phase 5 UAT 验收手册](phase-five-uat-acceptance.md)执行。

## 5. 晋级正式版本

UAT 通过后，在开发电脑确认正式标签和已验收 RC 指向同一提交：

```bash
git fetch --tags --prune origin
git switch main
git pull --ff-only origin main
git rev-list -n 1 v0.5.0-rc.3
git rev-parse HEAD
test "$(git rev-parse HEAD)" = "$(git rev-list -n 1 v0.5.0-rc.3)"
test -z "$(git tag --list v0.5.0)"
git tag -a v0.5.0 -m "GN-System v0.5.0"
git push origin v0.5.0
```

正式标签流水线不会重建镜像。它会找到同一提交上的最新 RC，将 app/web 镜像按原
digest 晋级为 `v0.5.0`，并在 digest 不一致时失败。流水线成功后再次检查两个正式
镜像，再在生产独立仓库切换 `v0.5.0`、修改 `.env.production` 的
`RELEASE_TAG`：

```bash
cd /srv/gn-system/repository
test -z "$(git status --porcelain --untracked-files=no)"
git fetch --tags --prune origin
git show --no-patch v0.5.0
git switch --detach v0.5.0
sudoedit .env.production
chmod 0600 .env.production
sudo ./deploy/deploy.sh .env.production
```

不得在 UAT 未通过、RC 镜像缺失或标签提交不一致时创建正式标签。

## 6. 回退与禁止事项

发布脚本分别把当前和历史版本记录在：

- UAT：`/srv/gn-system/uat/releases`
- 生产：`/srv/gn-system/releases`

健康检查失败且 migration 向后兼容时，把环境文件的 `RELEASE_TAG` 改回
`releases/current` 中记录的旧版本并重新运行发布脚本。已经执行不兼容 migration
时必须停止写入并从部署前验证过的备份恢复，不能只回退镜像。

服务器上禁止：

```text
nano app/...
git pull 后直接运行
composer install
npm install
docker build 生产镜像
删除或强制移动已推送标签
```

服务器出现问题时应收集 Compose 状态、日志和健康检查结果，在开发流程中修复并发布
新版本，不得直接热修服务器源码。
