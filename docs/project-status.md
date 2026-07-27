# GN-System 项目状态

> 最后核验：2026-07-27  
> 核验依据：当前 `develop` 分支的代码、配置、迁移和测试  
> 当前阶段：Phase 1 基础架构已完成；Phase 2 尚未开始

本页只描述仓库中可以验证的状态。未来规划见 `docs/source/`，不能据此页之外的
规划内容推断某项能力已经存在。

## 已实现

| 能力 | 当前状态 | 最后核验 |
|---|---|---|
| Laravel 13 / PHP 8.3 应用基础 | 已配置并锁定依赖 | 2026-07-27 |
| Livewire 4、Flux UI 2、Tailwind CSS 4 前端基础 | 已配置并可构建 | 2026-07-27 |
| 内部用户认证、关闭公开注册 | 已实现并有 Feature 测试 | 2026-07-27 |
| 超级管理员授权与强制 TOTP 2FA | 已实现并有 Feature 测试 | 2026-07-27 |
| Docker-first 本地运行环境 | 已提供应用、Nginx、PostgreSQL、Redis、Queue、Scheduler 和 Vite 服务 | 2026-07-27 |
| PostgreSQL 16 与 Redis 7 | 已作为当前数据库、缓存和队列基础设施 | 2026-07-27 |
| 存活、就绪及 Queue/Scheduler 心跳 | 已实现 | 2026-07-27 |
| 本地备份与清理调度 | 已实现；不等同于异地灾备 | 2026-07-27 |
| CI 质量门禁 | 已包含依赖校验、安全审计、格式、静态分析、测试和前端构建 | 2026-07-27 |
| 模块命名空间隔离检查 | 已有自动化测试，当前禁止任意跨业务模块导入 | 2026-07-27 |

## 仅有骨架

以下模块已注册 Service Provider，但除 Auth 外尚无实际领域模型、业务服务或数据表：

- Customer
- Agent
- Order
- Settlement
- Reminder
- Report
- Config
- Audit

模块目录存在不代表对应 CRM 功能已经实现。

## 尚未实现

- Phase 2 的业务数据模型和 Excel 导入、校验、预演及回滚
- Customer、Agent、Order、Settlement 等领域业务流程
- 跨模块 Application Contract、Application Service 或领域事件协作机制
- 月结与推广费核算
- CQRS、看板预聚合表和业务查询缓存
- 钉钉通知集成
- 正式生产平台、HTTPS、对象存储、异地备份和上线流程

## 更新规则

功能首次落地、能力移除或阶段变化时，必须在同一变更中更新本页。描述必须能由
代码、配置、迁移或测试验证；纯计划不得进入“已实现”。

