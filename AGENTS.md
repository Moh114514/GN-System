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
- 当前自动化规则只允许 Application 层定向引用其他业务模块的
  `Application/Contracts` 和 `Application/Data`；具体 Model、Service、
  Infrastructure、Presentation 和直接写表仍禁止。已落地契约及事务边界以
  `docs/architecture/module-boundaries.md` 和 Accepted ADR 为准，领域事件机制
  尚未落地，不得假设已经可用。
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

## 数据库安全

- 测试只能连接名称明确为 `gn_system_test` 或以 `_test` / `_testing` 结尾的独立测试数据库。
- `APP_ENV=testing` 不代表数据库一定安全；普通 Artisan CLI 不读取 `phpunit.xml` 中的环境变量。
- 禁止把 `php artisan migrate:fresh --env=testing` 作为通用测试准备命令，统一使用 `composer test`。
- Agent 执行任何破坏性数据库命令前，必须先输出并核对实际环境、连接和数据库名称。
- 未经用户明确批准，不得清空开发数据库；不得执行 `docker compose down -v`。
- 无法确认目标数据库时必须停止并报告，不得依赖静默 fallback 或默认数据库继续执行。
- 发现数据丢失时，不得声称可以恢复，除非已经找到并验证可用备份。

## 文档影响

每次变更都要判断是否影响：

- 架构或长期技术决策
- 数据模型、模块职责或公共接口
- 业务规则
- 环境、部署或开发流程
- 当前阶段和已实现能力

有影响时，按 `docs/development/documentation.md` 同步更新。普通重构、样式、
命名或不改变行为的小型修复通常无需修改文档，不要制造无意义的文档变更。

## 变更范围控制

- 优先完成满足需求的最小修改。
- 未经明确要求，不新增架构层、兼容层、投影表、缓存层或通用框架。
- 不得因为“未来可能需要”扩大当前任务范围。
- 发现额外问题时记录并报告，不得自动顺带重构。

## 验证规则

- 开发过程中先运行与修改直接相关的最小测试集。
- 完成实现后再运行一次完整质量门禁。
- 不得在每次小修改后重复运行全部测试，除非修改影响全局基础设施。
- 测试必须依据业务验收条件，不得仅复刻当前实现。

## 页面导航

- 修改或新增完整业务页面前阅读 `docs/development/ui-navigation.md`。
- 侧栏直接入口属于一级页面；每个二级、三级完整页面必须在内容顶部使用
  `resources/views/components/page-back.blade.php` 显示返回上一级按钮。
- 返回按钮必须指向明确的命名父路由，不得依赖浏览器历史；Feature 测试应验证按钮
  文字和目标路由。

## 错误与数据安全

- 禁止通过静默 fallback、吞异常或返回默认成功结果掩盖故障。
- 禁止静默截断输入、输出、名称或业务数据。
- 必须限制长度时，应验证并返回明确错误，且记录业务依据。

## 长任务控制

- 每完成一个可交付阶段，报告已修改文件、测试结果和未完成事项。
- 未经要求不得创建隐藏 worktree、额外分支或启动下一阶段。
- 如果连续两轮未产生有效代码进展，停止并重新检查目标与约束。
