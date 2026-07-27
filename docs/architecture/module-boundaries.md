# 模块边界

> 最后核验：2026-07-27  
> 决策依据：[ADR-0002](../adr/0002-module-boundaries-and-data-ownership.md)、
> [ADR-0004](../adr/0004-application-import-contracts.md)

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

DataImport 已使用同步 Application Contract 协调核心数据所有者，并在单一数据库
事务中提交或逆序回滚。这是当前唯一已经落地的跨模块协作模式。新增其他协作方式
仍需明确数据所有者、依赖方向、同步/异步语义和事务边界，并通过后继 ADR 扩展规则。

领域事件和通用跨模块 Application Service 尚未形成，不得从本次定向放行推断它们
可用。
