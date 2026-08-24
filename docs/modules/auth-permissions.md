# Auth 与权限模块

## 责任范围

Auth 拥有内部用户、兼容角色字段、业务组、业务组成员有效期和权限上下文。Agent 拥有代理商
到业务组的有效期归属。配置中心只编排两者的 Application Contract，不直接写用户、成员或
代理商归属表。

## 角色和有效范围

当前业务角色为：

- `super_admin`：全局配置、用户与权限管理以及所有业务范围；必须启用 TOTP 双因素认证。
- `bd_manager`：只能读取自己有效业务组和代理商归属范围内的数据；BD 季度提成为只读，不能
  代替超级管理员创建规则、生成、审核、确认或人工调整。
- `customer_service`：只能访问有效业务组和负责人范围内的客户、订单、提醒与相关报表。
  非负责人客服不得下载或导出敏感机构回传文件。

旧的内部用户角色值通过 `users.role` 兼容回填到当前角色模型；兼容字段不会绕过新的有效成员
和代理商归属检查。业务组成员、代理商归属和有效日期由 PostgreSQL 约束防止同一主体的重叠
有效期；未归属用户和代理商必须在发布前处理。

## AccessContext 规则

认证后由 Auth Application 层生成 `AccessContext`，包含角色、有效业务组、代理商、可见组内用户
和权限指纹。Customer、Agent、Order、Reminder、Settlement、Report 和导出流程只消费这个
上下文或其序列化快照，不得跨模块读取 Auth Model 或表。

范围必须在 Application 查询/写入边界和每个 Livewire 动作重新检查；路由、隐藏按钮和前端参数
不是授权边界。队列导出和私有文件下载还要核对创建者、当前用户和当前权限指纹，防止权限变化后
继续使用旧快照。Dashboard 缓存键也包含权限指纹，避免同一用户或不同业务组之间串读。

## 配置与审计

用户邀请、角色设置、启停、成员有效期、业务组和权限相关变更统一从
`/admin/configuration/users-and-notifications` 进入，并保留旧命名路由重定向。高风险操作记录
操作者、原因和 IP；不能停用自己或最后一个启用的超级管理员。

## 验证与发布边界

Feature 测试覆盖角色兼容、成员/代理商重叠约束、四类角色范围、跨业务组 URL、直接 Livewire
调用、下载/导出拒绝和缓存隔离。UAT 还必须使用真实测试账号逐项验证页面、直达 URL、下载、
导出、全局搜索和权限变更后的缓存隔离；本机测试不能替代 UAT/Production 验收。

权限上下文属于现有模块化单体内的 Application 协作，不引入通用 Policy 框架、领域事件或新的
投影数据库。跨模块约束见[模块边界](../architecture/module-boundaries.md)，跨订单与结算事实的
长期决策见[ADR-0010](../adr/0010-formal-order-facts-and-bd-commission-history.md)。
