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
