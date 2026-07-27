# 局域网生产部署与恢复

> 适用基线：Ubuntu Server 24.04 LTS x86-64、Docker Engine、Compose Plugin。  
> 本文描述单机生产基线，不适用于开发环境。

## 1. 容量与网络

建议服务器至少 4 核、8 GB 内存、250 GB SSD、千兆有线网络并接入 UPS。拓扑为：

`LAN → HTTPS Nginx → PHP-FPM → PostgreSQL / Redis`

只把服务器的 80/443 开放给指定局域网网段。5432、6379、9000 不发布宿主端口。
Docker 发布端口可能绕过普通 UFW 规则，因此应在 `DOCKER-USER` 链中限制来源，
并从允许及不允许的网段各做一次实际访问验证。防火墙规则属于主机配置，不由仓库
脚本自动修改，以免误断服务器连接。

准备正式域名的内网 DNS 和受客户端信任的证书。证书及私钥不能进入 Git 或镜像。

## 2. 首次准备

服务器只需安装 Git、Docker Engine、Compose Plugin、curl、rsync 和
mountpoint。将仓库放到 `/srv/gn-system/repository`，然后执行：

```bash
sudo timedatectl set-timezone Asia/Shanghai
sudo deploy/prepare-host.sh
cp .env.production.example .env.production
chmod 0600 .env.production
```

以强随机值填写所有空的密码和 `APP_KEY`，设置真实镜像地址、版本、服务器 LAN
地址、TLS 路径、SMTP 或 Sentry。至少验证一个告警渠道有效。把 `APP_KEY`、数据库、
Redis、备份密码另存于密码管理器或离线恢复信封。SMTP 使用 587/STARTTLS 时保留
`MAIL_SCHEME=smtp`；使用 465 隐式 TLS 时改为 `smtps`。

把证书安装到 `/srv/gn-system/tls/fullchain.pem` 和 `privkey.pem`，私钥权限设为
`0600`。先验证配置：

```bash
docker compose --env-file .env.production -f compose.production.yaml config
```

生产启动不会生成密钥或执行 migration。首次发布也统一使用发布脚本：

```bash
deploy/deploy.sh .env.production
```

首次发布后运行交互命令创建管理员：

```bash
docker compose --env-file .env.production -f compose.production.yaml \
  exec app php artisan app:create-admin
```

## 3. 发布与回退

只有 `v*` Git 标签通过完整门禁后才发布 GHCR 镜像。服务器使用只读 GHCR 凭据，
只拉取 `.env.production` 中的明确版本，禁止使用 `latest`、在服务器改代码或安装
依赖。

`deploy/deploy.sh` 会检查环境、记录旧版本、执行部署前全量备份、进入维护状态、
拉取镜像、执行 `migrate --force --isolated`、启动服务，并等待三个健康接口通过。
为它预留 5～10 分钟维护窗口。

所有后续 migration 必须采用 expand/contract：

1. 新增可选或有安全默认值的结构，代码同时兼容新旧结构。
2. 完成数据回填并观测。
3. 在后续独立版本切换读写。
4. 再后续版本才允许删除旧字段或表。

如果健康检查失败且 migration 仍向后兼容，修改 `RELEASE_TAG` 为
`/srv/gn-system/releases/current` 记录的旧版本后重新运行发布。若已经执行不兼容
数据库变更，停止写入并使用部署前备份恢复，不能只回退镜像。

## 4. 健康与日志

- `/up`：Laravel/PHP 进程存活。
- `/health`：PostgreSQL、Redis 就绪。
- `/health/operations`：Scheduler 和 Queue 心跳均不超过三分钟。

三个接口均不得返回异常、地址或凭据。停止 Redis、Queue、Scheduler 分别验证
503，再恢复服务。生产容器日志采用 `json-file`，单文件 10 MB、保留 5 份：

```bash
docker compose --env-file .env.production -f compose.production.yaml ps
docker compose --env-file .env.production -f compose.production.yaml logs --tail 200
```

服务器重启后检查所有服务自动恢复，并确认新队列任务由新代码处理。

## 5. 备份与异机同步

Scheduler 按 `APP_TIMEZONE=Asia/Shanghai` 每小时运行数据库加密备份，每日 02:00 运行数据库和
`storage/app/private` 全量加密备份，03:00 清理，04:00 监控。保留策略为 7 天全部、
30 天每日最新。

安装并启用异机同步 timer：

```bash
sudo cp deploy/systemd/gn-system-offsite-backup.* /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now gn-system-offsite-backup.timer
systemctl list-timers gn-system-offsite-backup.timer
```

默认异机挂载点为 `/mnt/gn-system-offsite`。同步脚本用 `mountpoint` 验证它确实是挂载
文件系统并测试可写性；失败时返回非零，绝不会把数据静默写入本机空目录。成功后更新
本地标记，应用每小时检查标记是否过期并通过已配置的异常渠道告警。还应让 systemd
失败状态进入主机监控。

## 6. 恢复与演练

恢复只能指向空数据库，且需要人工输入 `RESTORE`：

```bash
deploy/restore.sh .env.production /srv/gn-system/data/backups/<backup>.zip
```

恢复顺序为：准备相同 `APP_KEY` 和配置、恢复数据库、恢复私有文件、检查 migration、
检查三个健康接口、抽查业务数据及 2FA 用户登录。

每月在第二套空环境执行一次。记录镜像版本、备份文件、开始/结束时间、恢复耗时、
数据抽查、私有文件和 2FA 解密验证。初始 RTO 目标为 4 小时，数据库 RPO 目标为
1 小时。任何未实际恢复过的备份都不能视为已验证。

首版不配置 WAL/PITR；当一小时数据损失不可接受，或频繁 Dump 已明显影响业务时，
再通过独立 ADR 设计连续归档。
