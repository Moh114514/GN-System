# PR6 BD季度提成架构边界

PR6 的季度提成事实由 Settlement 模块拥有：规则版本、季度生命周期、订单明细、人工调整和
更正差额都只由 Settlement 写入。Settlement Application 通过 Order 的
`BdCommissionOrderReader` 读取 `occurred_on` 和订单内不可变业务归属快照，不在生成时重新读取
Agent/Auth 的当前归属 Model。

Order 完成订单或更正已完成订单时，只通过 Settlement 的
`BdCommissionCorrectionGateway` 通知已确认季度的更正，不直接引用 Settlement Model。Agent
通过 Auth Application Contract 读取发生日期对应的 BD 成员，并将其作为业务事实的一部分写入
订单快照。BD 查询在 Settlement Application 层按快照中的 BD 用户范围过滤；规则版本、生成、
人工调整、审核和确认在 Application Service 层执行超级管理员校验。

Report/Dashboard 只通过页面入口链接到 Settlement 的季度提成页面，不直接读取季度提成表；本 PR
不新增预聚合、事件总线、缓存或跨模块 Model 引用。
