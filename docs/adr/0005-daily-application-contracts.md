# ADR-0005：日常业务的同步 Application 契约

- 状态：Accepted
- 日期：2026-07-27
- 扩展范围：ADR-0004 仅覆盖历史导入的同步契约

## 背景

Phase 3 客户建档需要在一个请求内写入 Customer 拥有的档案、Order 拥有的首次预约
和 Audit 拥有的审计记录；客户详情还需读取 Agent、Config、Order、Reminder 和
Audit 的数据。直接访问其他模块的 Model 或数据表会破坏数据所有权，而仅为导入
定义的契约不足以支持日常业务。

## 决策

- 数据所有者在 `Application/Contracts` 和 `Application/Data` 暴露最小同步用例；
  调用方 Application 层只依赖契约和数据对象，不依赖具体实现。
- 客户建档由 Customer Application Service 协调，在一个 PostgreSQL 事务内调用
  Order 的首次预约契约和 Audit 的审计契约；任一失败使整个建档回滚。
- 列表筛选与详情时间轴使用数据所有者提供的只读查询契约。返回结果是标量数组或
  Application Data，不暴露 Eloquent Model。
- Customer 只写客户域数据；Order、Reminder 和 Audit 分别写预约/订单、跟进和
  activity log。Config 与 Agent 在本阶段只提供引用数据查询。
- 该决策不建立通用 Service Bus、领域事件框架或异步一致性机制。需要跨请求副作用
  时另行决策。

## 理由

- 同请求的建档与首次预约需要强一致性，同步事务比提前引入事件最终一致性更简单。
- 最小契约保留单一数据写入所有者，同时允许完成真实的跨模块业务页面。
- 将详情聚合放在 Application 层，避免 Presentation 绕过模块边界。

## 后果

- Application Contract 成为需要兼容维护的公共模块接口。
- 单库事务可以覆盖当前同步写入；若将来拆分服务，需要重新设计事务语义。
- 大数据量下的跨模块筛选需测量后再演进，不提前增加投影表或查询缓存。

## 验证

- `tests/Unit/ModuleBoundaryTest.php`
- `tests/Feature/CustomerLifecycleTest.php`
- Phase 3 客户建档、状态、时间轴与授权 Feature 测试
