# ADR-0007：月结批处理与员工提醒一致性

- 状态：Accepted
- 日期：2026-07-28
- 扩展范围：ADR-0005、ADR-0006 的同步 Application 契约

## 背景

Phase 5 需要按代理商异步生成月结，同时在订单完成时可靠建立未来术后提醒，并在
业务事务完成后发送钉钉通知。直接跨模块读表、在事务内调用外部 Webhook，或提前
引入领域事件总线都会扩大当前模块化单体的故障面。

## 决策

- 月结运行批次由 Settlement 拥有，按代理商拆分 Laravel Queue Job；数据库保存
  权威进度与失败原因，Redis 只保存可丢失的进度缓存。
- Settlement 通过 Order 和 Agent 的最小 Application Contract 读取周期订单与
  代理商等级上下文，只汇总已有不可变推广费快照，不重新计算历史推广费。
- 同一周期只有一个运行批次，同一代理商/周期只有一张月结。历史导入记录发生冲突时
  明确失败，不覆盖已有数据；失败代理商可独立重试。
- Order 完成事务通过 Reminder 的同步 Contract 幂等写入术后提醒实例。预约与可配置
  规则由 Scheduler 定时物化，稳定去重键防止重复；改期重排，取消关闭。
- 钉钉发送只在事务提交后由独立队列 Job 执行，最多重试三次。失败或未配置必须记录
  可见状态，但不回滚已经生成的月结或站内提醒。
- 本决策不引入领域事件总线、通用 Service Bus、CQRS 或跨服务事务。

## 理由

- 月结计算适合异步拆批，但进度和结果必须在 Redis 丢失后仍可审计。
- 订单完成与提醒建立需要当前单库内的强一致性；外部通知不应扩大业务事务。
- 沿用现有 Application Contract 规则可以保持数据所有权并控制公共接口规模。

## 后果

- Queue 与 Scheduler 是 Phase 5 业务流程的运行依赖，运维健康检查必须继续监控二者。
- Application Contract 和提醒去重键成为兼容接口；修改其语义需要新的 ADR。
- 钉钉真实送达依赖部署环境提供 Webhook 和 Secret，仓库测试使用 HTTP Fake。

## 验证

- `tests/Feature/PhaseFiveSettlementTest.php`
- `tests/Feature/PhaseFiveReminderTest.php`
- `tests/Unit/ModuleBoundaryTest.php`

