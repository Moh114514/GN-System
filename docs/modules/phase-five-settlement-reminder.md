# Phase 5 月结、结算单与主动提醒

## 月结边界

月结批次使用 `settlement_run_members` 保存每个代理商的处理结果；`SettlementRun` 只表示批次，`Settlement` 表示单个代理商在一个周期内的月结事实。推广费使用 KRW 作为内部计算基准；代理商等级是人工配置属性，不参与月结自动评估。

每条 Settlement 独立保存：

- `settlement_currency`：`KRW` 或 `CNY`
- `exchange_rate` / `exchange_rate_krw_per_cny`
- `exchange_rate_date`
- `exchange_rate_source`
- `total_consumption_krw`、`total_commission_krw`、`payout_amount_cny_fen`

KRW 月结不做汇率换算；CNY 月结按本次汇率计算并保存快照，之后报价接口变化不会改写已生成月结。

## 等级

等级策略、当前等级和生效历史仍由配置中心人工维护。月结预览、正式生成和刷新不会读取月业绩门槛，不会创建新的等级评估或升降级建议，也不会发送等级调整通知；历史相关表保留用于审计和迁移兼容。

## 通知

配置中心的通知负责人页面维护 `notification_recipient_configs`。月结不再触发等级调整通知；其他仍在使用的站内、钉钉通知继续按 `internal` / `dingtalk` 通道发送。钉钉负责人通过 `users.dingtalk_mention_type` 与 `users.dingtalk_mention_value` 绑定，支持企业 `user_id` 和普通群验证过的 `mobile`。未绑定用户不能被选择，Webhook 按绑定类型分别使用 `atUserIds` 或 `atMobiles` 定向 @，并在 Markdown 正文追加经过格式校验的 `@绑定值`，这是钉钉识别真实提及所需的组合。钉钉投递写入 `notification_deliveries`，由队列执行并自动重试。

## 主动提醒

当前系统自动生成的施术结束提醒只有术后 7 天和 30 天。提醒实例的幂等键包含客户、施术结束时间和提醒类型，回退并重新设置相同结束时间不会重复生成。旧生命周期自动提醒实例、旧系统规则和模板由客户生命周期清理迁移一次性删除。

新建主动提醒默认使用当前用户作为负责人，也只允许选择启用且已接受邀请的内部用户；自动生成的客户、预约和订单提醒继续沿用各自业务记录的负责人。

## 运行和验收
钉钉绑定迁移的回滚会在检测到仍存在 `mobile` 绑定时主动中止，以避免手机号绑定被静默丢弃；如确需回滚，必须先完成绑定导出或清理，并在目标环境确认后再执行。

月结 Queue 任务的失败回调使用独立的 `SettlementFailureRecorder`，不会再次解析完整的
`SettlementGenerator` 依赖链。`SettlementRunReconciler` 每分钟比较 `SettlementRun`、Laravel
Batch 和 `SettlementRunMember`；发现 Batch 失败、批次结束但 member 仍 pending、缺少批次或
运行过久时，批次标记为队列异常，月结中心提供重新派发等待任务的操作。

开发 Compose 使用 `queue:listen`；UAT/Production 使用 `queue:work`，发布后必须完成
`migrate --force`、`optimize:clear` 和 `queue:restart`。时间模拟页面的“设置并立即执行”表示
业务检查已提交到队列，不代表月结已经成功；最终结果以 Batch 和 member 状态为准。

月结仍通过现有批次、队列、重试、审核、结清、文档生成和历史归档流程运行。相关本地测试覆盖 KRW/CNY 审核、汇率快照、无等级副作用、提醒幂等、通知接口和日期边界；真实钉钉凭据、UAT/Production 迁移和人工业务验收仍需在目标环境执行。

## PR5 月结预览、生成日与等级暂停

PR6 的 BD 季度提成与代理商月结分开持久化，不复用 `settlements` 或
`settlement_items`。季度提成规则、订单归属快照、人工调整和确认状态见
`docs/modules/phase-six-bd-quarterly-commission.md`。

月结中心的预览调用 `SettlementCalculationService`，与正式生成共享订单和推广费计算，
但不写入批次、结算单、明细、等级评估、建议或通知表。正式生成默认在每月 5 日窗口处理
上一个自然月；旧配置按 `effective_from` 保留历史边界。

等级策略、当前等级、代理商分配、佣金规则、覆盖配置和历史快照继续保留；系统不再提供
通过环境变量恢复自动等级评估的路径。发布前应在目标环境确认迁移完成，以及月结仍生成
佣金且不产生新的等级评估、建议或通知。

## PR7 发布收尾

代理商月结和 BD 季度提成继续使用独立事实表、规则版本和确认生命周期；月结不读取 BD 当前
成员映射来重算已确认季度。发布 PR1–PR6 前必须按
[PR7 UAT 迁移与发布收尾手册](../operations/pr7-uat-migration-runbook.md) 完成备份、角色/业务组
映射、跨月订单抽样、预览与正式生成一致性、季度确认锁定和恢复方案核对。
