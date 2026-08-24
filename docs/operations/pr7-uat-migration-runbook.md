# PR7 UAT 迁移与发布收尾手册

## 目的和范围

本手册用于新规划 PR1–PR6 的 UAT 迁移、角色/业务组/代理商映射、业务抽样和失败恢复。
建议该能力从 `v0.6.0-rc.1` 开始新的不可变 RC 序列。这里的“完成”只表示目标环境按清单
验收完成；当前工作站没有连接或修改 UAT/Production。

服务器只能获取明确标签、切换到 detached tag、修改权限为 `0600` 的环境文件并运行发布脚本。
不得在服务器 `git pull` 后部署、安装应用依赖、修改业务代码、手工建表或手工删除数据。完整
目录、密钥和发布约束以[完整运维手册](operations-manual.md)和[发布管理手册](release-management.md)
为准。

## 1. 发布前置和分叉处理

开发电脑按以下顺序完成 PR：

```text
feature/business-groups-and-roles -> develop -> main -> v0.6.0-rc.1 -> UAT -> v0.6.0
```

在处理 `main` 与 `develop` 的分叉前，先由维护者核对两条分支的提交、未合入 PR、迁移顺序和
当前 RC；不得在服务器解决分叉。只有 PR 合入 `main`、完整门禁通过且 `HEAD` 与 `origin/main`
一致后，才创建新的 annotated RC。失败的 RC 不删除、不移动、不复用，修复后递增 RC。

本轮在工作站只完成文档和本地验证，不执行合并、推送、标签、部署或数据库恢复。

## 2. UAT 备份和只读预检

在 `/srv/gn-system` 的 UAT 仓库执行，先记录执行人、时间、目标标签、Compose 项目、数据库名、
当前运行版本和备份文件名。数据库与 `storage/app/private` 必须同时备份；备份成功且能够读取
清单后才能继续。使用既有发布脚本和备份命令，不使用 `docker compose down -v`。

发布前先完成以下无写入检查：

```bash
cd /srv/gn-system/repository
test -f .env.uat
test "$(stat -c '%a' .env.uat)" = 600
sudo docker compose --env-file .env.uat -f compose.production.yaml config --quiet
sudo docker compose --env-file .env.uat -f compose.production.yaml ps
sudo docker compose --env-file .env.uat -f compose.production.yaml exec -T app php artisan about
sudo docker compose --env-file .env.uat -f compose.production.yaml exec -T app php artisan migrate:status
```

数据库目标和业务前置条件使用仓库已批准的只读 SQL/应用查询执行，至少输出并归档以下计数；
SQL 必须由负责数据库的运维人员按当前 `.env.uat` 连接参数执行，禁止把密码写入命令行或聊天。
迁移前只能执行旧 schema 中已经存在的列；PR1/PR4/PR6 新表和新列必须在迁移后再执行对应检查。

```sql
select current_database(), current_user;

-- 迁移前：PR1/PR4 migration 会读取的旧 schema 阻塞项
select count(*) as active_super_admins
from users where is_super_admin = true and is_active = true;
select count(*) as pending_orders from orders where status = 'pending';
select count(*) as completed_orders_without_date
from orders where status = 'completed' and completed_on is null;
select count(*) as completed_orders_without_agent
from orders where status = 'completed' and agent_id is null;
select count(*) as completed_orders_without_commission_snapshot
from orders o
where o.status = 'completed'
  and not exists (select 1 from order_commissions c where c.order_id = o.id);
select count(*) as orders_with_unmapped_status
from orders where status not in ('pending', 'completed', 'cancelled');

-- PR1 直销残留和代理商归属
select count(*) as direct_customer_residual
from customers where original_channel = 'direct' or source_direct_sales_id is not null;
select count(*) as direct_order_residual
from orders where channel = 'direct' or direct_sales_source_id is not null;
select count(*) as customers_without_agent from customers where source_agent_id is null;
select count(*) as orders_without_agent from orders where agent_id is null;
```

迁移前的待完成订单、完成日期、代理商、推广费快照、状态、直销残留和缺少代理商归属必须为零；
这与 PR1/PR4 migration 的中止条件一致。`active_super_admins` 只用于确认至少存在
一个可用超级管理员，不是业务组归属计数。

迁移完成且页面尚未开放前，再执行新 schema 的只读核对：

```sql
-- PR1 迁移后：角色、有效成员和业务组
select role, count(*) from users group by role order by role;
select count(*) as users_without_active_group_membership
from users u
where u.is_active = true
  and u.role in ('bd_manager', 'customer_service')
  and not exists (
    select 1 from business_group_memberships m
    where m.user_id = u.id
      and m.effective_from <= current_date
      and (m.effective_until is null or m.effective_until >= current_date)
  );

-- PR1 迁移后：代理商归属
select count(*) as agents_without_active_group_assignment
from agents a
where a.is_active = true
  and not exists (
    select 1 from agent_business_group_assignments x
    where x.agent_id = a.id
      and x.effective_from <= current_date
      and (x.effective_until is null or x.effective_until >= current_date)
  );

-- 客户负责人和跨组归属
select count(*) as customers_without_valid_owner
from customers c
where c.owner_id is null
   or not exists (select 1 from users u where u.id = c.owner_id and u.is_active = true);

-- PR4 迁移后：订单事实
select count(*) as pending_orders from orders where status = 'pending';
select count(*) as completed_orders_without_occurred_on
from orders where status = 'completed' and occurred_on is null;
select count(*) as completed_orders_without_attribution_snapshot
from orders where status = 'completed' and business_attribution_snapshot is null;
select count(*) as completed_orders_without_commission_snapshot
from orders o
where o.status = 'completed'
  and not exists (select 1 from order_commissions c where c.order_id = o.id);
```

如果某个新列或表不存在，应在记录中标明对应 migration 尚未执行，不能把查询失败记成零。迁移
后的角色、未归属、负责人、跨组、日期/归属/推广费快照阻塞项必须为零，或有经过批准并记录的
映射方案；不满足时停止开放业务页面，不修改默认负责人、不删除历史记录。

## 3. 角色、业务组和代理商映射

PR1 migration 创建角色、业务组和有效期表后，超级管理员在新 RC 的配置中心完成以下顺序，
并保存页面审计记录；在旧版本页面中不能假设这些控件已经存在：

1. 确认每个真实测试账号的角色：超级管理员、BD、负责人客服、同组非负责人客服；停用无效账号，
   不停用最后一个超级管理员。
2. 创建或确认业务组，为每个业务组指定唯一有效 BD，并填写成员的有效起止日期。
3. 为每个代理商建立不重叠的有效业务组归属，处理未归属代理商。
4. 为每个客户确认有效负责人和业务组一致性；跨业务组负责人必须先由超级管理员纠正并审计。
5. 重新运行第 2 节的“迁移后”只读预检，确认所有阻塞项为零后，才开放业务页面和开始人工抽样。

配置入口为 `/admin/configuration/users-and-notifications`；业务组和代理商归属的具体标签以目标
RC 页面为准。不要通过直接 SQL 更新角色、成员或代理商归属来绕过有效期约束。

## 4. RC 迁移和抽样验收

第 2 节的迁移前预检和备份通过后，在 `/srv/gn-system/repository` 只切换明确 RC，然后运行：

```bash
git fetch --tags --prune origin
git show --no-patch v0.6.0-rc.1
git switch --detach v0.6.0-rc.1
sudo ./deploy/deploy.sh .env.uat
```

部署脚本负责拉取不可变镜像、执行 migration、重启 app/queue/scheduler、清理缓存和健康检查。
部署后先核对 `releases/current`、Compose 状态、`/up`、`/health`、`/health/operations`、Queue、
Scheduler 和备份列表，再按第 3 节完成映射并运行迁移后预检；预检通过后执行：

- 四类角色在页面、直达 URL、Livewire 操作、文件下载、导出和全局搜索中的权限检查；验证跨组
  不可见、客服敏感下载/导出被拒绝、BD 季度提成只能读自己的范围、Dashboard 缓存不串组。
- 客户负责人移交、BD 审核/驳回/直接移交/批量移交、未来预约和未完成提醒换负责人、历史跟进
  创建人不变、状态回退审批和批量原子性。
- 机构模板在 Excel/WPS 中下载、填写和回传；检查日期、跨月业务日、多个项目、金额、重复/篡改/
  并发回传、原始文件私有下载、订单/客户/推广费/提醒/审计是否原子一致。
- 订单编辑的乐观锁和已结算锁定；月结预览与正式生成相等，默认每月 5 日窗口，旧配置仍按历史
  生效日；等级评价开关关闭时无副作用但佣金仍生成。
- BD 季度规则版本、季度边界、代理商/BD 中途转移、草稿重算、确认锁定、人工调整审计及订单
  更正后续季度差额；预览不写入事实表。

每项验收记录账号、业务组、代理商、客户/订单/季度样本 ID、预期、实际、截图或导出校验值和
操作者。业务日期必须以 `occurred_on` 为准，例如 8 月 31 日到院、9 月 1 日消费、9 月 3 日上传
仍归入 9 月业务口径。

## 5. 失败、回退和恢复

失败时先停止业务写入，保存容器状态、日志、健康接口、migration 状态、备份文件和 RC 信息。
若只是向后兼容的应用问题，按发布手册把 `RELEASE_TAG` 改回 `releases/current` 记录的旧版本，
再运行同一发布脚本。若已执行 PR1/PR4/PR6 的不兼容或破坏性 migration，不能只切回旧镜像，
也不要直接执行 `migrate:rollback`、删除新表或手工改列；必须按批准的恢复方案从已验证备份恢复
数据库和私有文件，并完成数据库名、migration、健康、队列、调度、登录和抽样核对。

数据库与私有文件恢复必须保持同一备份时间点。恢复脚本只允许恢复到明确的空目标库，具体命令、
密码输入和私有目录同步以[完整运维手册第 13 节](operations-manual.md)为准。恢复完成前不得
重新打开业务写入；恢复结论由运维负责人记录并由业务负责人复核。

## 6. Production 晋级

UAT 全部通过后，正式标签 `v0.6.0` 必须与已验收 RC 指向同一提交，并晋级同一 app/web 镜像
digest；不得重新构建。Production 使用 `/srv/gn-system/production` 独立仓库、环境文件、数据卷、
端口、凭据和发布历史，不得复制 UAT 业务数据或 UAT 通知凭据。生产只执行一次经过备份和预检的
迁移，并用新版本初始化角色、业务组、代理商归属、费率和模板配置；BusinessClock 只用于非生产/UAT，
生产不使用模拟时间。

本机无法验证服务器分叉、标签、镜像 digest、备份可恢复性、UAT/Production migration、真实账号、
Queue/Scheduler 或人工业务流程；这些项目在发布记录中必须保持“未验证”，直到目标环境有证据。
