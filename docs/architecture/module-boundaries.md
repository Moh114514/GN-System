# 模块边界

> 最后核验：2026-07-27  
> 决策依据：[ADR-0002](../adr/0002-module-boundaries-and-data-ownership.md)、
> [ADR-0004](../adr/0004-application-import-contracts.md)、
> [ADR-0005](../adr/0005-daily-application-contracts.md)

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

DataImport 使用同步 Application Contract 协调历史导入。Phase 3 Customer 使用
最小同步 Contract 读取 Agent/Config 引用数据、聚合 Order/Reminder/Audit 详情，
并在建档事务中调用 Order 首次预约和 Audit 审计写入。各数据所有者仍只写自己的表。

领域事件、通用 Service Bus 和异步跨模块一致性机制尚未形成，不得从同步契约放行
推断它们可用。
