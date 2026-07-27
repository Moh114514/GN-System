# 模块边界

> 最后核验：2026-07-27  
> 决策依据：[ADR-0002](../adr/0002-module-boundaries-and-data-ownership.md)

## 当前模块

`app/Modules/` 下包含 Auth、Customer、Agent、Order、Settlement、Reminder、
Report、Config 和 Audit。共享技术能力位于 `app/Infrastructure/`，不属于业务
模块。

## 当前可执行规则

`tests/Unit/ModuleBoundaryTest.php` 扫描业务模块内的 PHP 文件，并禁止一个模块
通过 `use App\Modules\...` 导入另一个业务模块命名空间。

因此，当前规则比“只禁止引用其他模块 Model”更严格：

- 允许模块引用自身命名空间、Laravel 和非业务模块的基础设施能力。
- 禁止直接引用其他业务模块的 Model、Service、DTO、Contract 或 Event。
- 禁止直接写入其他模块拥有的数据表。

边界测试只检查 PHP `use` 导入，不能证明动态类名、字符串调用、原生 SQL 或数据
表所有权完全合规。代码审查和功能测试仍必须检查这些路径。

## 依赖方向

模块内部预期采用：

`Presentation -> Application -> Domain`

Infrastructure 可以实现 Application 或 Domain 定义的技术接口，但不得让领域层
依赖具体框架设施。除 Auth 外，业务模块的这些层目前尚未实际建立。

## 跨模块协作

Application Contract、Application Service 和领域事件是计划中的协作方式，不是
当前已具备的机制。第一次需要跨模块协作时，必须在实现前：

1. 明确数据所有者、同步或异步语义以及事务边界。
2. 通过新 ADR 或对 ADR-0002 的替代决策确认公共契约放置方式。
3. 同步调整边界测试，使其精确允许公共契约而继续禁止具体实现泄漏。
4. 增加契约和集成测试，并更新相关模块文档。

在上述机制落地前，不得通过删除或放宽边界测试来实现跨模块调用。

