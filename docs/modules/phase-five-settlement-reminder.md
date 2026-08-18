# Phase 5 月结、结算单与主动提醒

## 月结边界

月结批次使用 `settlement_run_members` 保存每个代理商的处理结果；`SettlementRun` 只表示批次，`Settlement` 表示单个代理商在一个周期内的月结事实。推广费、等级评估和等级门槛始终使用 KRW 作为内部计算基准。

每条 Settlement 独立保存：

- `settlement_currency`：`KRW` 或 `CNY`
- `exchange_rate` / `exchange_rate_krw_per_cny`
- `exchange_rate_date`
- `exchange_rate_source`
- `total_consumption_krw`、`total_commission_krw`、`payout_amount_cny_fen`

KRW 月结不做汇率换算；CNY 月结按本次汇率计算并保存快照，之后报价接口变化不会改写已生成月结。

## 等级评估

`agent_grade_evaluations` 以代理商和结算周期唯一记录本期结果：`upgrade`、`maintained` 或 `downgrade_failure`，同时记录连续未达标次数。升级当期即可生成 `SettlementGradeSuggestion`；降级只有连续且相邻的两个结算周期未达标才生成建议。重跑同一周期会更新评估记录，不会重复累加；如果中间缺少结算周期，失败次数会从 1 重新计算。系统只生成建议，等级仍由管理员人工确认后从下月生效。

## 通知

配置中心的通知负责人页面维护 `notification_recipient_configs`。等级调整建议会生成站内通知，并按 `internal` / `dingtalk` 通道发送；钉钉负责人通过 `users.dingtalk_user_id` 绑定，未绑定用户不能被选择，Webhook 请求使用 `atUserIds` 定向 @。钉钉投递写入 `notification_deliveries`，由队列执行并自动重试，状态记录为 `queued`、`sending`、`sent` 或 `failed`。提醒实例已有负责人时复用同一 UserId 规则；没有负责人时只发送群通知。

## 主动提醒

当前系统自动生成的施术结束提醒只有术后 7 天和 30 天。提醒实例的幂等键包含客户、施术结束时间和提醒类型，回退并重新设置相同结束时间不会重复生成。旧生命周期自动提醒实例、旧系统规则和模板由客户生命周期清理迁移一次性删除。

## 运行和验收

月结仍通过现有批次、队列、重试、审核、结清、文档生成和历史归档流程运行。相关本地测试覆盖 KRW/CNY 审核、汇率快照、升级建议、连续降级门槛、提醒幂等、通知接口和日期边界；真实钉钉凭据、UAT/Production 迁移和人工业务验收仍需在目标环境执行。
