# ADR-0006：订单完成与推广费核算的同步 Application 契约

- 状态：Accepted
- 日期：2026-07-28
- 扩展范围：ADR-0005 的日常业务同步契约

## 背景

Phase 4 在订单进入“已完成”状态时必须固化推广费。Order 拥有订单，Settlement
拥有推广费，Agent 拥有合作状态、政策等级及其生效历史。若三个模块直接访问彼此
的数据表会破坏数据所有权；若此时引入异步事件，则订单可能已经完成但推广费核算
失败，超出当前系统的一致性和运维能力。

现行费率口径是月初生效的政策等级、等级机构固定基点费率和代理商特批，不采用
月内累计阶梯。历史订单佣金必须继续以已有快照为准。

## 决策

- Order Application Service 在 PostgreSQL 事务中更新订单，并同步调用 Settlement
  的 `DailyCommissionGateway`；Settlement 只通过 Agent 的
  `AgentCommissionContextReader` 获取当月有效等级。
- 代理商订单按“机构特批、全机构特批、等级机构费率”顺序解析；直销订单不生成
  推广费。
- 金额以 KRW 整数保存，费率以基点保存，使用 `brick/math` 半入取整。每个订单
  只允许一条推广费记录，重复完成返回既有结果。
- 缺少有效等级、缺少费率或代理商不是合作中状态时抛出明确业务错误，订单状态、
  推广费和审计记录全部回滚。
- 推广费快照保存代理商、政策、等级、机构、规则来源与记录、生效月、订单金额、
  费率、结果和计算时间；后续配置变更不重算历史。

## 理由

- 同步事务满足当前单库模块化单体的强一致要求，并避免提前引入事件重试、补偿和
  最终一致性运维。
- 公共 Data/Contract 保持数据所有者边界，同时使费率解析规则可以独立测试。
- 唯一约束和不可变快照保证重复请求安全及历史可审计。

## 后果

- 订单完成请求依赖 Agent 与 Settlement 同步可用，任一核算失败都会阻止完成。
- Application Contract 成为需要兼容维护的公共接口。
- 若未来采用异步领域事件，必须以新 ADR 重新定义投递、重试、补偿和用户可见状态。

## 验证

- `tests/Feature/PhaseFourAgentCommissionTest.php`
- `tests/Unit/ModuleBoundaryTest.php`
- `tests/Unit/AgentCodeNormalizerTest.php`
