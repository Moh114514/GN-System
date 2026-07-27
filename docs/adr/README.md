# Architecture Decision Records

ADR 记录具有长期影响、存在真实取舍且需要后续开发者理解原因的决策。普通实现
细节、Bug 记录和短期任务不写 ADR。

## 状态

每份 ADR 只能使用以下状态之一：

- Proposed：正在评审，尚未成为约束
- Accepted：已经生效
- Superseded：已被更新决策替代，并注明替代 ADR
- Rejected：评审后未采用

ADR 一旦 Accepted 不直接改写决策内容。需要改变决策时新增 ADR，并把旧 ADR
标记为 Superseded。

## 编号与流程

1. 复制 [模板](template.md)。
2. 使用下一个四位编号和简短英文文件名，例如 `0003-event-policy.md`。
3. 写清背景、决策、理由、后果和验证方式。
4. 同一编号只能存在一个 ADR 文件。
5. 合并 Accepted ADR 时同步更新相关当前态架构文档和实现检查。

