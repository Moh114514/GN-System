# GN-System 文档导航

这里是仓库知识入口。项目当前事实以代码和测试为最高依据；阅读任何规划资料前，
请先查看[项目状态](project-status.md)。

## 当前事实

| 文档 | 用途 | 权威程度与维护责任 |
|---|---|---|
| [根目录 README](../README.md) | 启动、运维、质量门禁和项目概览 | 必须与可运行仓库保持一致；相关实现变更时更新 |
| [项目状态](project-status.md) | 区分已实现、仅有骨架和未实现能力 | 当前状态摘要；功能落地或阶段变化时更新 |
| [当前架构概览](architecture/overview.md) | 已经采用的技术和部署结构 | 只描述当前实现；架构变化时更新 |
| [模块边界](architecture/module-boundaries.md) | 当前模块隔离规则及自动化保障 | 必须与边界测试一致 |

## 架构决策

- [ADR 目录与模板](adr/README.md)
- [ADR-0001：Laravel 13 基础](adr/0001-laravel-13-foundation.md)
- [ADR-0002：模块边界与数据所有权](adr/0002-module-boundaries-and-data-ownership.md)
- [ADR-0003：单机局域网生产部署基线](adr/0003-single-host-production-baseline.md)
- [ADR-0004：导入场景的跨模块 Application 契约](adr/0004-application-import-contracts.md)
- [ADR-0005：日常业务的同步 Application 契约](adr/0005-daily-application-contracts.md)
- [ADR-0006：订单完成与推广费核算的同步 Application 契约](adr/0006-synchronous-order-commission-contract.md)
- [ADR-0007：月结批处理与员工提醒一致性](adr/0007-phase-five-settlement-reminder-processing.md)

## 模块实现

- [Phase 2 核心数据与导入模块](modules/phase-two-core-data.md)
- [Phase 3 客户全生命周期](modules/phase-three-customer-lifecycle.md)
- [Phase 4 代理商与推广费核算](modules/phase-four-agent-commission.md)
- [Phase 5 月结、结算单与主动提醒](modules/phase-five-settlement-reminder.md)
- [Phase 6 多维查询、数据看板与配置中心](modules/phase-six-reporting-configuration.md)
- [订单中心](modules/order-management.md)

Accepted ADR 记录已经生效的长期决策。若 ADR 与代码不一致，应把它作为需要处理的
偏差，而不是假装代码已经符合 ADR。

## 业务需求与设计来源

- [来源文档说明](source/README.md)
- [CRM 需求文档 v1.9](source/CRM-需求文档-v1.9.md)：业务需求上游
- [CRM 系统架构设计](source/CRM-系统架构设计.md)：历史设计与未来方案输入
- [CRM 开发技术路线](source/CRM-开发技术路线文档.md)：阶段规划
- [CRM 系统架构图](source/CRM-系统架构图.html)：规划视图

`docs/source/` 中的“已确认”表示需求或方案已经确认，不表示代码已经实现。
其中可能保留历史版本、备选技术和未来数据流；判断当前能力必须回到项目状态、
代码和测试。

## 开发规范

- [文档维护规则](development/documentation.md)
- [测试与数据库隔离](development/testing.md)
- [页面层级与返回导航](development/ui-navigation.md)
- [GN-System 小白运维指南](operations/beginner-operations-guide.md)
- [GN-System 完整运维手册](operations/operations-manual.md)
- [UAT 测试版本与正式发布流程](operations/release-management.md)
- [Phase 5 UAT 验收手册](operations/phase-five-uat-acceptance.md)
- [局域网生产部署与恢复](operations/production-deployment.md)
- Agent 工作规则见根目录 [AGENTS.md](../AGENTS.md)

模块文档在模块进入实际业务开发时按需创建，不为空模块骨架预建占位文档。
普通变更历史由 Git 和 Pull Request 保存；数据库变化由 migration 保存。只有
真实生产事故才在未来按需建立 `docs/incidents/`。
