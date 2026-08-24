# 当前架构概览

> 最后核验：2026-08-24
> 本文只描述当前仓库已经采用的架构，不描述未来业务数据流。

## 系统形态

GN-System 是单一 Laravel 应用和单一部署单元，业务代码按领域目录组织为模块化
单体。它不是微服务系统，也没有独立 SPA 或对外业务 API。

当前主要组成：

- PHP 8.3、Laravel 13、Laravel Fortify
- Livewire 4、Flux UI 2、Tailwind CSS 4、Alpine.js
- PostgreSQL 16 作为唯一当前主数据库
- Redis 7 用于缓存和队列
- 开发环境由 Nginx、PHP-FPM、Queue、Scheduler 和 Vite 独立容器组成
- 生产环境由 TLS Nginx、PHP-FPM、Queue、Scheduler、PostgreSQL 和 Redis 组成

数据库已经在代码、Compose 和 CI 中确定为 PostgreSQL。源设计文档中保留的
MySQL 内容只是历史备选，不是当前支持矩阵。

## 代码布局

- `app/Modules/`：Auth、核心业务模块和 DataImport 协调模块
- `app/Infrastructure/`：健康检查等共享技术能力
- `app/Providers/`：应用级和认证基础配置
- `routes/`：Web、设置和调度命令入口
- `database/`：迁移、Factory 和 Seeder

Auth 已有完整认证实现；Customer 已交付全生命周期页面；Agent 已交付档案、政策
等级与配置页面；Order 与 Settlement 已交付最小订单完成、推广费核算和月结审核
闭环；Reminder 已交付面向内部员工的主动提醒中心；Report 已交付查询、看板和
导出协调；Config 已交付统一配置目录、用户管理协调和配置历史聚合。模块现状详见
[项目状态](../project-status.md)，隔离规则详见
[模块边界](module-boundaries.md)。

## 运行与部署边界

仓库提供相互独立的开发 Compose 和单机生产 Compose。生产基线为 Ubuntu Server
24.04 LTS、不可变 app/web 镜像和 Nginx HTTPS；不运行 Vite、不挂载源码，也不在
容器启动时生成密钥或迁移数据库。开发 Compose 的 Laravel 运行时目录使用命名卷，
PostgreSQL、Redis 使用命名卷，私有文件与加密
备份使用受控宿主目录，并由 systemd timer 同步至异机挂载点。同机 UAT 复用生产
镜像和 Compose，但通过独立仓库、Compose 项目、端口、数据、凭据和发布历史隔离；
当前 UAT 位于 `/srv/gn-system`，Production 位于 `/srv/gn-system/production`，
旧的反向目录方案已经废弃。正式标签只晋级已验收 RC 的原镜像 digest，不重新构建。
当前环境参数与操作命令见[完整运维手册](../operations/operations-manual.md)，具体约束见
[ADR-0003](../adr/0003-single-host-production-baseline.md)和
[生产部署手册](../operations/production-deployment.md)，版本流程见
[发布管理手册](../operations/release-management.md)。

## 业务数据与导入

Phase 2 采用 PostgreSQL 关系模型保存机构、代理商、客户、预约、订单、跟进、推广费
快照和月结。DataImport 拥有 staging 与批次元数据，通过数据所有者模块的同步
Application Contract 在单一事务内提交和回滚。私有源文件应用层加密，客户联系方式
和证件使用加密列与不可逆盲索引。

## 代理商订单与推广费

Agent 保存代理商、类型、政策等级及月度生效历史；Settlement 保存等级机构固定
基点费率、代理商机构/全机构特批及订单推广费快照。Order 完成订单时使用同步

PR6 在 Settlement 内新增版本化 BD 提成规则和季度事实。Settlement 通过 Order 的
`BdCommissionOrderReader` 读取按 `occurred_on` 的完成订单，订单的
`business_attribution_snapshot` 保存发生日对应的业务组和 BD 成员快照；季度明细、人工调整、
审核确认和更正差额均由 Settlement 自己持久化。Order 只通过 Settlement 的
`BdCommissionCorrectionGateway` 通知已确认季度的订单更正，不直接写 Settlement 表。
Application Contract 在同一 PostgreSQL 事务中核算和审计；失败时订单完成一并回滚。
当前所有订单均归属代理商并产生推广费。当前没有领域事件或异步核算。

## 月结、文档与主动提醒

Settlement 使用版本化周期配置和按代理商拆分的队列任务，从订单推广费快照生成
权威月结批次。数据库保存进度和失败原因，Redis 只提供实时进度；审核、汇率换算、
结清和等级建议均为显式人工操作。Word、PDF 和批量 ZIP 从同一个不可变视图模型
生成并保存在私有磁盘。

Reminder 保存规则、模板、提醒实例和生命周期事件。订单完成事务同步幂等创建术后
提醒，Scheduler 通过只读 Application Contract 扫描预约和客户日期规则。钉钉通知
由事务提交后的独立队列任务发送；未启用或失败不会回滚站内提醒或月结数据。

## 查询、看板与配置

Report 只编排数据所有者提供的只读 Application Contract/Data。Order 执行订单事实
分页和聚合，Customer、Agent、Config、Settlement、Reminder 分别解析敏感查询、
批量名称、推广费/月结和提醒口径；Report 不直接引用其他模块 Model 或查询其表。
护照仅通过 Customer 的规范化 HMAC 盲索引精确定位，绝不解密扫描。

看板聚合最多缓存五分钟，缓存异常时记录告警并直接执行数据库聚合。PDF 与 HTML
使用同一服务端不可变快照。Excel 与服务端看板导出保存
在私有磁盘，并由创建者授权下载、Queue 生成和 Scheduler 过期清理。

配置数据仍由 Agent、Customer、Settlement、Auth 与 Config 各自写入。Config 页面
只调用 Application 契约聚合操作；高风险配置快照分表保存在所属模块，回滚在所属
模块的单一事务中完成，不重算历史订单推广费或已结算快照。

Auth 还拥有用户角色、业务组及成员有效期历史；Agent 拥有代理商到业务组的有效期
历史。两类历史均由 PostgreSQL 日期约束保护重叠关系，配置页面通过 Auth/Agent 的
Application Contract 管理并展示未归属完整性检查。该基础能力属于新规划 PR1，尚未
改变订单主流程或 PR2 的业务数据范围执行。

## 尚未形成的架构

完整订单 CRUD、领域事件、CQRS 和看板预聚合尚未实现。对应源文档是后续设计输入，
不能据此推断当前能力。

## PR2 access context

The current feature branch adds an Auth-owned access context for role, effective business-group membership, agent assignment, group-user ownership, and a permission fingerprint. Application readers and gateways apply that context to business records, reports, dashboards, saved queries, exports, and settlement documents. Queued exports carry a serialized snapshot and are checked against the current creator context when downloaded. This is scope enforcement inside the existing modular monolith; it does not introduce domain events, CQRS, a new projection store, or a general policy framework.

## PR4 institution return and order facts

The Order module now receives completed business facts from a versioned institution XLSX
template. The generated workbook contains a very-hidden metadata sheet with a form UUID and
HMAC signature. The parser accepts Excel serial dates, date objects, and supported string date
forms, then validates customer identity, date consistency, item quantities, unit prices, and
amounts before processing.

The original workbook is encrypted into the private storage root. A successful upload atomically
creates the order fact, item snapshots, commission snapshot, customer treatment completion, two
postoperative reminders, and audit record. SHA-256 and form UUID uniqueness prevent duplicate
processing. The `occurred_on` business date is independent from upload time, so cross-month
uploads remain in the month in which the business occurred. Manual order creation and manual
completion are no longer exposed by the Order pages.

## PR5 order editing and settlement calculation

Order edits are coordinated by the Order application service and lifecycle gateway. The gateway
keeps customer, institution, source-agent, and original-file references immutable, validates item
amounts, uses an optimistic-lock timestamp, records an audit diff, and rebuilds only unsettled
commission snapshots in the same transaction. The Agent module exposes a narrow date-based
business-attribution reader so the edited `occurred_on` date determines the saved group snapshot.

Settlement preview and formal generation share the pure `SettlementCalculationService`; preview
does not create settlement-side rows. Settlement readers use the order business date while keeping
legacy completed-date snapshot keys for document compatibility. Grade evaluation is explicitly
feature-gated and disabled by default through `AGENT_GRADE_EVALUATION_ENABLED`.
