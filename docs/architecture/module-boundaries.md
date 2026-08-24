# 模块边界

> 最后核验：2026-08-24
> 决策依据：[ADR-0002](../adr/0002-module-boundaries-and-data-ownership.md)、
> [ADR-0004](../adr/0004-application-import-contracts.md)、
> [ADR-0005](../adr/0005-daily-application-contracts.md)、
> [ADR-0006](../adr/0006-synchronous-order-commission-contract.md)、
> [ADR-0007](../adr/0007-phase-five-settlement-reminder-processing.md)

## 当前模块

`app/Modules/` 下包含 Auth、Customer、Agent、Order、Settlement、Reminder、
Report、Config、Audit 和 DataImport。共享技术能力位于 `app/Infrastructure/`，
不属于业务模块。

## 当前可执行规则

`tests/Unit/ModuleBoundaryTest.php` 扫描业务模块内的 PHP 文件。跨模块引用仅在
调用方位于 Application 层，且目标是数据所有者模块的 `Application/Contracts`
或 `Application/Data` 时允许。

- 允许模块引用自身命名空间、Laravel 和非业务模块的基础设施能力。
- 允许 Application 层定向引用其他模块的公共 Contract 和 Data。
- 禁止直接引用其他业务模块的 Model、具体 Service、Repository、Infrastructure、
  Presentation 或 Event。
- 禁止直接写入其他模块拥有的数据表。

边界测试只检查 PHP `use` 导入，不能证明动态类名、字符串调用、原生 SQL 或数据
表所有权完全合规。代码审查和功能测试仍必须检查这些路径。

## 依赖方向

模块内部预期采用：

`Presentation -> Application -> Domain`

Infrastructure 或模块内 Application Service 可以实现 Application Contract，但
不得让领域层依赖具体框架设施。

## 跨模块协作

DataImport 使用同步 Application Contract 协调历史导入和基础配置导入。基础配置
批次按 Config/Customer/Agent 的字典、Agent 政策等级、Settlement 机构费率、Agent
档案与等级分配的顺序，在一个事务中调用各数据所有者契约；DataImport 不直接写入
这些模块的数据表。Phase 3 Customer 使用
最小同步 Contract 读取 Agent/Config 引用数据、聚合 Order/Reminder/Audit 详情，
并在建档事务中调用 Order 首次预约和 Audit 审计写入。各数据所有者仍只写自己的表。

Phase 4 Agent Application 通过 Customer/Order/Config/Settlement 的最小 Contract
聚合代理商详情和配置；Order Application 在完成订单的事务中同步调用 Settlement
核算，Settlement 通过 Agent Contract 读取当月等级。Order 只写订单表，Settlement

PR6 Settlement Application 通过 Order 的 `BdCommissionOrderReader` 获取季度订单事实，
只使用订单内不可变业务归属快照，不在报表生成时读取 Agent/Auth 的当前归属 Model。Order
在已完成订单更正事务中只调用 Settlement 的 `BdCommissionCorrectionGateway`；季度规则、
明细、人工调整和确认事实仍由 Settlement 独占，BD 范围过滤也在 Settlement Application 层执行。
只写费率、特批和推广费表，Agent 只写代理商及政策等级表。

Phase 5 Settlement Application 通过 Order、Agent 的只读 Contract 获取月结订单、
代理商资格和等级建议上下文；等级调整仍由 Agent Contract 安排下月生效。Order
完成事务同步调用 Reminder Contract 创建术后系列提醒，Reminder 定时扫描通过
Order、Customer 只读 Contract 生成预约和日期规则实例。队列 Job 只调用本模块
Application Service；外部钉钉通知在业务事务提交后执行。

Phase 6 Report Application 通过 Customer、Agent、Order、Config、Settlement、
Reminder 和 Auth 的只读 Contract/Data 组合查询与看板快照。Order 只查询自己的
订单事实，Auth 仅批量映射负责人姓名，其他数据所有者解析筛选、批量名称和各自聚合，
Report 不跨模块引用 Model/Builder。
Config Application 通过 Agent、Customer、Settlement 的配置历史 Contract 和 Auth
的用户管理 Contract 聚合配置页面；每个数据所有者仍独占实际写入、快照和回滚。

Auth 拥有 `users.role`、`business_groups` 和 `business_group_memberships`，并通过
`BusinessGroupReferenceReader` 与 `BusinessGroupManagementGateway` 暴露业务组、成员
有效期和未归属用户查询/写入契约。Agent 拥有
`agent_business_group_assignments`，通过 `AgentBusinessGroupAssignmentGateway`
暴露代理商归属有效期和未归属代理商查询/写入契约。Config 只编排这些 Application
Contract，不直接引用对应 Model 或写入业务表；订单主流程和业务数据范围不由本 PR 改变。

领域事件、通用 Service Bus 和异步跨模块一致性机制尚未形成，不得从同步契约放行
推断它们可用。

## PR2 access scope boundary

Auth owns the `AccessContext` snapshot and permission fingerprint. Other modules consume it through the Auth application contract; they must not read user roles, memberships, or assignment tables directly across module boundaries. Agent exposes the agent assignment scope through its application contract. Presentation and queued export flows may rehydrate a serialized access snapshot, but must still enforce the current user and current fingerprint before serving a file or mutating data.

Scope enforcement belongs at the module application query/gateway boundary and at every Livewire write action. A route or hidden button is not an authorization boundary. Customer Service response DTOs must remain minimal, and Settlement read-only BD access must not reuse administrator write operations.

## PR3 customer transfer and rollback boundary

Customer owns `customers.arrived_at`, `customer_transfer_requests`,
`customer_owner_histories`, and `customer_status_change_requests`. Its Application
services own the transactional transfer and status-approval workflows. Customer may
consume Auth's `AccessContextResolver`, active Customer Service membership reader and
user reference contract, Order's future-appointment/order-existence contract, Reminder's
unfinished-reminder transfer contract, Config's internal-notification contract, and
Audit's recorder contract. Customer must not read those modules' models or tables
directly. Follow-up and historical status records remain owned by their existing
Customer/Reminder contracts, and the transfer workflow changes only the owner of
future/open work; it does not rewrite historical creators.

The effective-target check is performed inside the locked transaction. A pending
transfer is applied only after review and is invalidated if the source owner or target
scope changed. Batch transfers lock customers in deterministic ID order and roll back
the whole batch on any invalid item. Status rollback requests use the same Customer
status manager with an explicit approval marker; the manager still enforces the current
actor's scope and the no-order rollback rule.

## PR4 institution return and order facts boundary

Order owns `institution_form_templates`, `institution_return_files`, `order_items`, and the
new order fact columns (`occurred_on`, `record_status`, `business_attribution_snapshot`, and
`source_return_file_id`). Its Application layer owns template generation, hidden metadata
signing, fixed-form parsing, encrypted private storage, duplicate protection, and the atomic
institution-return processor. Order may consume only the Config institution reader, Customer
customer/order reference and treatment-completion contracts, Agent reference contract,
Settlement daily commission contract, Reminder treatment-reminder contract, and Audit recorder
contract. It must not import those modules' Models or write their tables directly.

Customer owns the customer status transition performed through
`CustomerTreatmentCompletionGateway`; it does not create orders or reminders as a side effect.
The Order processor schedules the two postoperative reminders exactly once after the customer
completion contract succeeds. Settlement owns the commission snapshot, Reminder owns reminder
instances, and Audit owns audit records. This remains synchronous application-contract
coordination; domain events, a general event bus, and asynchronous cross-module consistency are
not assumed.

The `2026_08_24_000200_add_institution_return_order_facts.php` migration refuses to start when
legacy orders cannot be mapped safely and refuses rollback after order facts, order items, or
original return files exist. Private source files are encrypted before being written to the
configured private disk and are served only after scope checks.

## PR5 order and settlement boundary

Order may consume Agent's `AgentBusinessAttributionReader` contract and Settlement's existing
commission contracts; it does not write Settlement models directly. Settlement consumes Order's
`SettlementOrderReader` and Agent's `SettlementAgentGateway`, while its pure calculation service
is shared by preview and formal generation. Settlement preview has no persistence side effects.
The grade pause is configuration-driven and does not remove policy, grade, assignment, commission,
or historical settlement capabilities.
