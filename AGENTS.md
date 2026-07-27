# GN-System Agent Development Guide

本文件规定 Agent 在本仓库中的工作方式，不承载完整业务知识。

## 开始工作前

1. 阅读根目录 `README.md`，了解运行和验证方式。
2. 阅读 `docs/README.md` 和 `docs/project-status.md`。
3. 涉及架构或模块时，阅读相关架构文档、已接受的 ADR 和已有模块文档。
4. `docs/source/` 是需求与历史设计输入，不代表对应能力已经实现。

## 事实与证据优先级

发生冲突时，按以下顺序判断当前事实：

1. 当前代码、迁移、配置和自动化测试
2. 状态为 Accepted 的 ADR
3. `docs/project-status.md` 和当前态架构文档
4. 已确认的业务需求
5. 技术路线、架构设想和其他规划资料

发现冲突时不得静默选择方便实现的版本；应修正文档，或在无法判断产品意图时提出问题。

## 架构与变更规则

- GN-System 当前是 Laravel 模块化单体，使用 PostgreSQL、Redis、Livewire 和 Docker。
- 修改模块代码前检查 `docs/architecture/module-boundaries.md` 和
  `tests/Unit/ModuleBoundaryTest.php`。
- 当前自动化规则禁止业务模块导入其他业务模块的命名空间。跨模块 Contract、
  Application Service 或领域事件机制尚未落地，不得假设已经可用。
- 不得通过删除测试、降低检查强度或修改文档描述来掩盖实现问题。
- 数据库结构变更必须提供 migration，并补充相应测试。
- 新增依赖、公共接口或长期性技术约束时，应说明理由和影响。

## 验证

根据变更风险运行相应检查；提交前的完整门禁为：

```powershell
docker compose exec app composer ci:check
docker compose exec vite npm run build
```

仅修改文档时至少运行：

```powershell
docker compose exec app composer docs:check
```

如果容器不可用，可使用等价的本地 PHP/Composer 命令，但应在交付说明中写明实际执行内容。

## 文档影响

每次变更都要判断是否影响：

- 架构或长期技术决策
- 数据模型、模块职责或公共接口
- 业务规则
- 环境、部署或开发流程
- 当前阶段和已实现能力

有影响时，按 `docs/development/documentation.md` 同步更新。普通重构、样式、
命名或不改变行为的小型修复通常无需修改文档，不要制造无意义的文档变更。

