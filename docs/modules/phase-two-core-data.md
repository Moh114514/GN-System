# Phase 2 核心数据与导入模块

> 当前范围：历史数据导入所需的数据层和最小管理界面；不等同于完整业务 CRUD。

## 数据所有权

| 模块 | 拥有的数据 |
|---|---|
| Config | 机构及机构别名 |
| Agent | 类型代码、代理商、合同、政策体系、等级、生效历史、机构费率和特批 |
| Customer | 编号序列、客户、代理商归属、联系方式、证件、生命周期状态及历史 |
| Order | 预约和消费订单 |
| Reminder | 历史跟进记录 |
| Settlement | 订单推广费快照、月结及月结明细 |
| Audit | activity log |
| DataImport | 导入批次、加密源文件元数据和 staging 行 |

DataImport 只能通过各模块的 `Application/Contracts` 和 `Application/Data` 协调写入。
日常客户业务使用的同步契约见 Phase 3 模块文档与 ADR-0005。

## 已固化的业务规则

- 代理商编号统一为 `简称-类型代码`；历史 `KR-DY` 导入为 `DY-KR` 并保留旧编号。
  客户编号同步转换，建档后永久不变。
- 客户编号为 `{代理商编号}-四位流水`，客户必须归属一个代理商。
- 订单必须归属一个代理商；订单完成时按有效等级和机构费率生成代理商推广费快照。
- 客户联系方式和证件号使用 Laravel encrypted cast 保存；规范化后的值使用 HMAC
  盲索引做精确候选匹配。系统只提示疑似重复，不自动合并。
- KRW 金额使用整数，费率使用整数基点。月结保存 KRW/CNY 汇率字符串快照和 CNY
  分金额，结果四舍五入。
- 代理商等级和机构费率按月初生效并整月固定；当月业绩只产生下月建议，人工复核后
  下月生效。

## 导入生命周期

超级管理员且已启用 2FA 才能进入导入页。系统接受单个不超过 20MB 的
 `.xlsx`、`.xls`、`.csv`，源文件加密存入 private storage。CSV 支持 UTF-8 和
GB18030，并固定使用英文逗号作为分隔符，不依赖自动推断。

上传前会检查启用中的代理类型和机构是否齐全。解析阶段保存每个文件的
格式、编码、分隔符、工作表、表头行和识别类型；无法识别的表头会明确失败，不再
静默归类为代码表。解析结果先进入 staging；XLSX 日期字段按单元格原值、日期格式和
数据类型读取，并在 staging 中保留原值、格式化值、规范化值、单元格类型和数字格式；
页面展示批次计数、前 50 行和错误报告。代理商引用按当前规范编号、历史编号、名称和
安全名称规范化顺序匹配，写入明细和汇总的始终是规范编号；多个候选会阻止提交。未知
机构、未知代理商、未知代理类型、编号冲突、重复候选和月明细/汇总差异会阻止
正式提交。月结汇总使用“代理商编号 + 结算周期”核对月明细；旧模板缺少结算周期时，
优先复用由结算日期解析出的 \`period_start\` / \`period_end\`，无法确定时才根据唯一消费月份
推导并记录提示。结算周期明确且客户数、消费额、KRW/RMB 推广费均为零时，即使没有当月
明细也作为合法零消费月结导入；非零汇总无对应明细、当月明细全部无效或汇总与有效明细
不一致仍会阻止提交。零待处理项时先取
最多 100 条有效数据完成一次强制回滚的事务预演；正式提交在一个数据库事务内完成，
任一错误使整批不落库。

已完成批次有 24 小时回滚窗口。回滚前各模块检查导入记录是否被后续修改；有阻塞
时返回表名和记录 ID。完成 24 小时后清除源文件和 staging 原始敏感数据；失败或
未完成批次默认保留 7 天。

## 基础配置导入

配置中心提供与历史业务数据导入相互隔离的“基础配置导入”。超级管理员下载一个
XLSX 填写示例，在七个固定工作表中维护代理商类型、机构及别名、政策
体系、政策等级、机构费率规则、代理商档案和代理商等级分配。代理商当前只支持一个
`legacy_code`，不把机构别名机制错误套用为代理商多别名。

上传后源文件同样加密保存，队列解析工作表、表头、字段格式、工作簿内重复键和跨表
引用。页面最多预览前 100 行校验结果；零错误后还必须完成一次强制回滚的事务预演。
上传、解析和预演均不修改配置。只有管理员勾选最终确认后，系统才在一个 PostgreSQL
事务中依次执行“基础字典 → 政策等级 → 费率 → 代理商 → 等级分配”，任一步失败
整批回滚，并记录批次审计。基础配置批次不套用历史业务数据的 24 小时自动回滚，
修改前应保留已验证备份。

## 当前限制

- 导入页分别提供“结构示例”和“可导入模拟数据”两个下载；结构示例带不可导入标记，
  可导入模拟数据按当前启用的基础数据动态生成。
- 基础配置导入提供单独的“填写示例”下载，工作表名称和表头必须保持不变；允许保留
  无数据的空工作表。
- 真实历史文件尚未迁移和抽样核对。
- 代理商 CRUD、等级配置中心、订单 CRUD、月结审核和提醒调度属于后续阶段。

## 本地模拟数据

开发环境执行以下命令可生成确定性的完整调试场景数据：

```powershell
docker compose exec app php artisan db:seed
# 或：docker compose exec app php artisan db:seed --class=DevelopmentScenarioSeeder
```

`DevelopmentScenarioSeeder` 生成 10 个固定测试用户、2 个业务组、15 个代理商、200 个客户、
250 笔包含未来预约/取消/跨月/边界金额的订单、推广费、5 个月月结、220 条跟进、70 条提醒、
10 个导入批次、季度 BD 提成和 120 条审计记录。数据覆盖生命周期、权限范围、提醒、月结、
导入状态和报告查询等开发场景；业务记录均含“【模拟】”标识，联系方式和证件是无真实含义的
固定测试值。Seeder 使用稳定业务键，可重复执行而不增加记录，并会拒绝在 production 环境运行。

统一测试密码为 `password`。常用账号：`admin@example.test`（超级管理员）、
`bd.a@example.test`（BD A）、`service.a1@example.test`（客服 A1）。旧的
`PhaseTwoDemoDataSeeder` 仍保留，用于 Phase 2 最小数据专项测试。
## PR-B import issues and stage state

Import batches use `import_issues` to record file detection, field validation, normalization, relation validation, summary validation, dry-run, and commit issues. Encrypted context is retained for diagnostics; XLSX reports read only from this table and write explicit string cells. `summary.stages` persists each stage status and counters for the Livewire page.

Only explicitly ignorable optional-data issues may be adjudicated. Unresolved agents, customer-agent conflicts, unresolved settlement periods, summary/detail mismatches, dry-run failures, and database constraint exceptions remain non-ignorable.
