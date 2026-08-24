# ADR-0010：正式订单事实、业务日期与 BD 季度提成历史快照

- 状态：Accepted
- 日期：2026-08-24
- 替代：更新 ADR-0006 和 ADR-0007 中以“订单完成”概括正式业务事实的表述；其同步 Application Contract、单库事务和外部通知不扩大事务的约束继续有效。

## 背景

机构回传是当前订单事实的唯一正式入口。上传时间可能与业务发生月份不同，代理商、业务组和
BD 也可能在订单发生后变更。若月结或季度提成重新读取当前归属，就会改写历史口径；若各模块
直接写对方的表，又会破坏模块边界和失败回滚语义。

## 决策

1. 版本化机构固定表单校验成功后，由 Order 在一个 PostgreSQL 事务中创建正式订单、订单明细、
   客户施术完成状态、术后提醒、推广费快照和审计记录。`pending -> completed` 只允许由这条
   完整回传事务触发，页面不提供人工完成入口。
2. `orders.occurred_on` 是订单的业务发生日期，按该日期解释月结和季度边界；上传时间只用于
   文件和处理审计，不替代业务日期。
3. Order 在正式订单和后续允许的订单更正中保存业务归属快照，包括代理商、业务组和 BD 成员。
   Settlement 生成历史提成时只读取订单快照，不重新解析 Auth 或 Agent 的当前映射。
4. Settlement 独占 BD 规则版本、季度事实、明细、人工调整、审核确认和更正差额。它通过
   `BdCommissionOrderReader` 读取 Order 事实；Order 只通过
   `BdCommissionCorrectionGateway` 通知已确认季度的更正，不写 Settlement 表。
5. 订单推广费和 BD 季度提成继续使用同步 Application Contract，并在同一数据库事务内完成。
   暂不引入领域事件、通用消息总线或依赖异步补偿的跨模块一致性。钉钉等外部通知仍在事务提交
   后由队列发送，失败不回滚站内业务事实。
6. 已确认季度不可重算。确认后的订单更正以 Settlement 认可的调整记录进入后续季度，保留
   原始事实、调整原因、操作者和审计信息。

## 理由

- 使用正式机构回传作为唯一完成入口，能把表单签名、重复提交、日期校验和跨模块回滚放在同一
  个可验证边界内。
- 保存发生日快照可以同时满足历史可追溯性和业务组/BD 中途转移，不需要冻结当前组织配置。
- 由 Settlement 保存规则和历史结果，避免 Order、Auth、Agent 之间形成反向数据耦合，也保持
  月结与季度提成的独立生命周期。
- 同步契约适合当前单体和单一 PostgreSQL；在没有可靠事件基础设施前，不用异步流程掩盖失败。

## 后果

- PR4、PR5、PR6 migration 必须先经过目标环境数据预检和备份，不能通过手工 SQL 绕过迁移。
- UAT 必须抽查跨月上传、代理商/BD 中途转移、重复回传、确认锁定和后续季度更正。
- 业务组、成员和代理商当前配置只影响新订单或新的有效日期，不得批量重写既有订单快照。
- 退款、完整财务冲正和已结算月结反结算仍不属于本 ADR 的能力范围。

## 验证

- `tests/Feature/InstitutionReturnFormTest.php`、`tests/Feature/OrderManagementTest.php` 和
  `tests/Feature/PhaseFourAgentCommissionTest.php` 验证正式回传、日期、明细、推广费和事务回滚。
- `tests/Feature/BdQuarterlyCommissionTest.php` 验证季度边界、规则版本、预览/正式生成、确认锁定、
  BD 范围和更正差额。
- `tests/Unit/ModuleBoundaryTest.php` 验证跨模块只通过 Application Contract/Data 协作。
- UAT 迁移、抽样和恢复步骤见[PR7 UAT 迁移与发布收尾手册](../operations/pr7-uat-migration-runbook.md)。
