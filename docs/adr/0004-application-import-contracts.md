# ADR-0004：导入场景的跨模块 Application 契约

- 状态：Accepted
- 日期：2026-07-27
- 替代范围：ADR-0002 中“业务模块之间零命名空间导入”的部分约束

## 背景

历史数据导入及基础配置导入需要在一个 PostgreSQL 事务内协调 Config、Agent、
Customer、Order、Reminder、Settlement 和 Audit。直接引用其他模块的 Model、具体 Service 或数据表
会破坏数据所有权；完全禁止跨模块引用又无法实现这一同步用例。

## 决策

- 数据所有者模块在 `Application/Contracts` 声明导入、校验和回滚能力，在
  `Application/Data` 声明跨边界数据。
- 其他业务模块的 Application 层只能定向引用上述两个公共命名空间。
- 跨模块 Model、Infrastructure、具体 Service、Repository、Presentation 和直接
  写表继续禁止。
- DataImport 是协调者，只拥有 `import_batches`、`import_files`、`import_rows`，
  正式提交和回滚在单一 PostgreSQL 事务中同步执行。
- 基础配置导入复用同一所有权约束，以批次 `kind` 与历史业务数据导入隔离；按依赖
  顺序调用数据所有者契约，上传和预演不落配置，管理员确认后才原子提交。
- 回滚按 Settlement、Reminder、Order、Customer、Agent、Config 的逆序调用契约；
  各所有者负责报告导入后被修改的记录并删除自己拥有的数据。
- 该放行只适用于同步 Application 契约，不代表领域事件或通用跨模块 Service 已经
  建立。

## 后果

- 数据所有权保持单一，同时允许原子导入。
- 公共契约成为需要兼容维护的模块接口。
- 长事务仅用于管理员发起的历史迁移，不作为日常高并发业务模式。
- `ModuleBoundaryTest` 精确允许 `Application/Contracts` 和 `Application/Data`，
  并继续拒绝具体实现泄漏。

## 验证

- `tests/Unit/ModuleBoundaryTest.php`
- DataImport 的解析、权限、数据约束和回滚 Feature 测试
