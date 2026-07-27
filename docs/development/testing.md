# 测试与数据库隔离

## 数据库职责

- `.env`：本地开发应用配置，默认连接开发数据库 `gn_system`。
- `.env.testing`：本地测试进程的显式配置，必须连接独立测试数据库
  `gn_system_test` 或名称以 `_test` / `_testing` 结尾的数据库。该文件包含本地凭据，
  被 Git 忽略。
- `.env.testing.example`：可提交的测试环境模板，不包含真实密码。
- `phpunit.xml`：PHPUnit 测试进程的补充配置。普通 `php artisan ...` 命令不会读取它，
  因此不能把 `--env=testing` 当作数据库隔离机制。

Laravel 在指定 `--env=testing` 但找不到 `.env.testing` 时，可能继续使用 `.env` 或容器
进程已有的环境变量。项目禁止这种静默回退。

## 首次准备

在仓库根目录复制模板：

```powershell
Copy-Item .env.testing.example .env.testing
```

如测试数据库需要密码，仅在本地 `.env.testing` 中增加 `DB_PASSWORD`，不要修改或提交模板。

全新的 PostgreSQL 数据卷会通过
`docker/postgres/init/01-create-test-database.sql` 创建 `gn_system_test`。初始化脚本不会在已有
数据卷上重复运行。若测试库不存在，可在保留现有 Volume 的情况下显式创建：

```powershell
docker compose exec postgres createdb -U gn_system gn_system_test
```

执行前先用下面的只读命令确认目标和现有数据库列表，避免重复创建：

```powershell
docker compose exec postgres psql -U gn_system -d postgres -c "\l"
```

## 推荐测试命令

统一从应用容器运行：

```powershell
docker compose exec app composer test
```

完整提交门禁：

```powershell
docker compose exec app composer ci:check
docker compose exec vite npm run build
```

安全测试入口会先确认 `.env.testing` 存在，并显式覆盖子进程的 `APP_ENV`、
`DB_CONNECTION`、`DB_DATABASE` 和 `DB_URL`。数据库名称不符合测试库规则时立即退出。
测试框架需要重建表结构时，应用内的第二道保护会再次核对：

- 当前环境必须是 `testing`；
- 命令必须由项目安全测试入口启动；
- 配置连接必须是 `pgsql`；
- 配置数据库和 PostgreSQL `current_database()` 必须相同且均为测试库；
- `.env.testing` 必须实际存在。

## 禁止和受保护的操作

不要使用以下命令准备常规测试：

```text
php artisan migrate:fresh --env=testing
php artisan migrate:reset --env=testing
php artisan db:wipe --env=testing
```

项目会硬性阻止 `migrate:fresh`、`migrate:reset` 和 `db:wipe` 绕过安全入口运行。
不得对开发数据库执行清空操作，也不得执行 `docker compose down -v`、删除数据库 Volume
或使用带 Volume 清理的 Docker 命令。

停止项目并保留数据库、Redis 和备份 Volume：

```powershell
docker compose down
```

## 确认当前连接

普通应用 CLI 的只读确认：

```powershell
docker compose exec app php artisan tinker --execute="dump(app()->environment(), config('database.default'), DB::connection()->scalar('select current_database()'));"
```

测试配置的安全预检：

```powershell
docker compose exec app php scripts/run-tests.php --preflight
```

任何数据库重建前，都必须明确记录环境、连接和 `current_database()` 的结果。不能只看
`APP_ENV`，也不能只看配置文件中的数据库名称。

## 排查误连与重建测试库

如果测试入口报告 `gn_system`、数据库名称为空、实际数据库无法确认，或配置库与实际库不一致：

1. 立即停止，不要增加 `--force` 绕过。
2. 检查 `.env.testing` 是否存在且 `DB_DATABASE=gn_system_test`。
3. 检查 Compose 容器环境是否覆盖了主机配置。
4. 用 PostgreSQL 只读查询确认 `current_database()` 和数据库列表。
5. 确认目标是测试库后，再通过 `composer test` 让测试框架初始化表结构。

若确需重建测试数据库，应先输出环境、连接和实际数据库名称，并取得相应授权。不得把
`gn_system` 作为验证保护逻辑的真实破坏目标；保护逻辑的回归测试使用纯策略测试或临时测试库。
