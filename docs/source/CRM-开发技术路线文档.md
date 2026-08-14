# 模块一·客户管理系统（CRM）开发技术路线文档

> **文档性质**：阶段规划与历史设计来源，不代表相应能力已经实现。PostgreSQL 已
> 被当前实现确定为主数据库；MySQL 仅作为本文保留的历史备选。当前状态见
> [`docs/project-status.md`](../project-status.md)。
>
> **文档版本**：v2.0（对齐需求文档 v1.9、系统架构设计 v1.2，并追加当前基线后的增量路线）
> **适用模块**：客户管理系统（CRM）
> **业务背景**：医美/医疗行业，需将现有 4 份 Excel（客户跟进表、代理商月结表等）迁移为 Web 系统，覆盖客户全生命周期、代理商结算、多维查询、数据看板与系统配置
> **核心目标**：以标准化、可扩展、可维护为原则，把 7 个子任务拆解到 8 个交付阶段，配套完整的基础设施与工程化体系
> **架构基线**：模块化单体（Modular Monolith）· Laravel 13 + Livewire 4 + Flux UI 2

> **Phase 2 实现口径（2026-07-27）**：本文所有 `KR-简称` 示例统一按
> `简称-KR` 实现，旧值仅作为 `legacy_code` 导入；所有“月度累计阶梯/JSONB 阶梯”
> 规划统一收敛为“等级 + 机构固定基点费率 + 代理商特批”，月初快照整月固定，当月
> 业绩只产生人工复核的下月等级建议。客户和订单均支持代理商、直销两类渠道，客户
> 编号永久不随订单渠道变化。本口径覆盖后续未逐项改写的历史方案描述。

---

## 目录

1. [业务理解与现状分析](#1-业务理解与现状分析)
2. [技术栈选型：完善与补充](#2-技术栈选型完善与补充)
3. [各项需求的具体技术实现方案](#3-各项需求的具体技术实现方案)
4. [分阶段技术路线（按开发优先级）](#4-分阶段技术路线按开发优先级)
5. [关键里程碑总览](#5-关键里程碑总览)
6. [风险评估与应对策略](#6-风险评估与应对策略)
7. [团队配置与协作建议](#7-团队配置与协作建议)
8. [附录：参考资料与备选方案](#8-附录参考资料与备选方案)
9. [基于当前 develop 的增量路线（2026-08-14）](#9-基于当前-develop-的增量路线2026-08-14)

---

## 1. 业务理解与现状分析

### 1.1 业务理解

| 维度 | 业务描述 |
|------|----------|
| 行业属性 | 医美/医疗（出现"施术项目"、"术后 X 天"等专业术语） |
| 客户特征 | 涉外客户（存在"护照号"、"翻译"字段） |
| 核心实体 | 客户、代理商、政策体系、等级、机构、施术项目、订单、结算单 |
| 业务流 | 建档 → 预约 → 到院 → 回访 → 复购（5 阶段 + 7 档状态，**状态名称与流转规则可在后台配置，当前命名为系统默认值**，2026-07-24 修订） |
| 结算流 | **政策体系 → 等级 两层结构**（可自由创建）→ 佣金规则引擎（浅深度，月度累计阶梯）→ 推广费核算 → 月结（**周期可自定义，默认自然月**）→ 结算单（Word/PDF） |
| 代理商状态 | 合作中 / 暂停 / 已终止（暂停不产单、不参与月结） |
| 成交金额 | **全人工录入实际成交价**，不对接外部系统（C3） |
| 跟进提醒 | 全流程：术前/到院/术后 1/7/30/90/180 天 + 可配置主动跟进 + 客服自定义（F2），**钉钉推送**（Q13） |
| 现有痛点 | Excel 手工维护、`#VALUE!` 浮点错误、查询困难、提醒依赖人工 |

### 1.2 现有需求清单（已确认口径）

| # | 任务 | 业务工时 | 关键技术诉求（v1.6 口径） |
|---|------|------|--------------|
| 1 | 现有 Excel 的系统化迁移 | 1 周 | 4 个 Excel 数据结构化，**金额 BIGINT(分)** |
| 2 | 客户全生命周期管理 | 1 周 | 5 阶段 + 7 档状态，**流转规则与状态名称可在后台配置**（默认值） |
| 3 | 代理商档案 + 推广费自动核算 | 1 周 | **政策体系两层 + 规则引擎浅深度 + 月度累计阶梯**，修复 `#VALUE!` |
| 4 | 月结汇总 + 结算单生成 + 主动跟进中心 | 1 周 | 月结 < 5 分钟（**周期可自定义，默认自然月**），Word/PDF，**全流程跟进 + 钉钉推送** |
| 5 | 多维查询 | 0.5 周 | 9 维度任意组合，**仅 AND 关系**（Q08） |
| 6 | 首页数据看板 + 月度图表 | 1 周 | 6 项核心数据 + **to B/to C 双视角**，**导出 PDF/图片/HTML**（Q14） |
| 7 | 系统配置中心 | 0.5 周 | 政策体系/等级/规则/用户管理，**权限两档**（Q12/C3-4） |
| **合计** | — | **6 周** | — |

> ⚠️ **重要提示**：原需求 6 周仅为"业务功能开发"工时，**未包含**：基础架构、CI/CD、测试、部署、运维、文档、培训等工程化工作量。完整周期需 10–12 周（详见第 4 节路线图）。

---

## 2. 技术栈选型：完善与补充

> 建议主线：**Laravel 13 + Livewire 4 + Flux UI 2 + PostgreSQL（推荐）/ MySQL 8 + Redis + Docker**。
> 架构形态：**模块化单体**（详见《CRM-系统架构设计.md》第 2 章）。

### 2.1 完善后的完整技术栈

#### 2.1.1 后端核心（必选）

| 技术 | 版本 | 用途 | 选型理由 |
|------|------|------|----------|
| **PHP** | 8.3+ | 运行时 | 类型系统完善、性能优于 8.0/8.1 |
| **Laravel** | 11.x | Web 框架 | 生态成熟、Queue/Schedule/ORM/Events 强 |
| **Livewire** | 3.x | 前端交互 | 与 Laravel 无缝集成、避免 SPA 复杂度 |
| **Eloquent ORM** | 内置 | 数据访问 | 配合 Repository 模式，模块化单体边界 |
| **Laravel Queue** | 内置 | 异步任务 | 月结、提醒排程、导入、推送，以及达到阈值后的预聚合 |
| **Laravel Schedule** | 内置 | 定时任务 | 月结触发、提醒扫描 |
| **Laravel Events** | 内置 | 模块解耦 | OrderCompleted → 核算/提醒（领域事件） |
| **brick/math** | 0.12+ | 高精度金额 | BigDecimal 根治 `#VALUE!`，与 BIGINT(分) 配合 |

#### 2.1.2 前端核心（必选）

| 技术 | 版本 | 用途 | 选型理由 |
|------|------|------|----------|
| **Flux UI** | 2.x | UI 组件库 | 官方 Starter Kit、Light/Dark/System 主题 |
| **Tailwind CSS** | 4.x | 样式 | 与 Flux UI 配套、utility-first |
| **Alpine.js** | 3.x | 前端轻交互 | Livewire 内置，无需独立安装 |
| **ECharts** | 5.5+ | 图表 | to B/to C 双视角看板 |
| **Livewire PowerGrid** | latest | 表格 | 多维查询结果展示 |
| **Three.js**（可选） | latest | 首页 Hero 沉浸效果 | 提升高级感，降级处理不拖累性能 |

#### 2.1.3 数据层（必选）

| 技术 | 版本 | 用途 | 选型理由 |
|------|------|------|----------|
| **PostgreSQL**（推荐）/ MySQL | 16 / 8.0+ | 主库 | JSONB 存规则快照、复合索引、并发事务；金额一律 **BIGINT(分)** |
| **Redis** | 7.x | 缓存/队列/会话 | 配置缓存、看板缓存、月结进度；队列故障通过监控、重试和运维恢复处理 |
| **自定义两档权限** | — | 权限模型 | 超级管理员 / 普通内部用户（C3-4 最简，普通用户看全量 Q12） |
| **Spatie Laravel Activitylog** | 4.x | 操作审计 | 合规要求（客户敏感信息变更追溯） |

> 🔴 **权限模型变更**：原 v1.0 用 Spatie Permission 做细粒度 RBAC；v1.6 确认**仅两档**（C3-4），用 `users.is_super_admin` 布尔 + 中间件即可，不再引入 Spatie Permission，降低复杂度。

#### 2.1.4 文件与文档处理（任务 1/4/6 必需）

| 技术 | 用途 | 应用场景 |
|------|------|----------|
| **maatwebsite/excel** | Excel 导入导出 | 任务 1：4 个 Excel 迁移；错误报告 |
| **phpoffice/phpword** | Word 文档生成 | 任务 4：结算单 Word 下载（默认简洁模板，无需 Logo/签章 Q22） |
| **barryvdh/laravel-dompdf** | PDF 生成 | 任务 4：结算单 PDF；任务 6：看板导出 PDF |
| **html2canvas / puppeteer** | 图片/HTML 导出 | 任务 6：看板导出 PNG / HTML（Q14） |
| **intervention/image** | 图片处理 | 客户头像、护照图片压缩 |

#### 2.1.5 工程化（原文档缺失，已补充）

| 技术 | 用途 | 说明 |
|------|------|------|
| **Docker + Docker Compose** | 开发/生产环境一致性 | nginx + php-fpm + redis + postgres 一键启动 |
| **Nginx** | 反向代理 | Laravel 官方推荐 |
| **PHP-FPM 8.3** | PHP 进程管理 | 与 Nginx 配合 |
| **Supervisor** | 队列进程守护 | Laravel Queue 必备 |
| **Git + GitHub/GitLab** | 版本控制 | 必选 |
| **GitHub Actions** | CI/CD | PR 触发 PHPUnit + PHPStan + Pint，main 触发部署 |
| **PHPStan (level 6) + Laravel Pint** | 静态分析 + 代码风格 | 质量保障 |
| **PHPUnit 12** | 自动化测试 | Unit + Feature |
| **Sentry** | 异常监控 | 生产环境必备 |
| **spatie/laravel-backup** | 自动备份 | PostgreSQL 每日全量 + WAL 归档，RPO≤24h |

#### 2.1.6 通知与消息（v1.6 收敛）

| 技术 | 用途 | 应用场景 |
|------|------|----------|
| **Laravel Notifications** | 统一通知抽象 | 渠道分发 |
| **钉钉 Webhook** | 即时通讯 | **唯一推送渠道**（Q13 确认），术后/月结/等级提醒 |
| **Laravel Mail** | 邮件（备用） | 备份通知、月结报告 |

> 🔴 **通知渠道变更**：原 v1.0 含企业微信/短信/邮件多渠道；v1.6 确认**仅钉钉**（提醒是发给内部员工，不发客户 Q21），短信/企业微信移除。未来需扩展走 PushAdapter。

#### 2.1.7 安全与合规（已补充）

| 技术 | 用途 | 应用场景 |
|------|------|----------|
| **2FA (pragmarx/google2fa)** | 双因素认证 | 超级管理员登录强制 |
| **字段级加密（Encryptable）** | 敏感字段加密存储 | 护照号、联系方式（Q07） |
| **数据脱敏** | 自定义中间件/Accessor | 列表脱敏（手机号 138****5678），详情按权限解密 |
| **HTTPS + HSTS** | 传输安全 | 必选 |
| **CSP / XSS 防护** | Laravel 默认 | 必选 |
| **审计日志** | Spatie Activitylog | 横切全模块，只追加不可篡改 |

#### 2.1.8 性能与可观测性（已补充）

| 技术 | 用途 | 应用场景 |
|------|------|----------|
| **Laravel Debugbar (dev)** | 性能分析 | N+1 查询检测 |
| **Laravel Telescope (dev)** | 请求追踪 | 开发期 |
| **Sentry** | 异常监控 | 生产 |
| **Laravel Horizon**（可选） | 队列监控 | Queue 任务可视化 |

#### 2.1.9 MySQL 替代方案指引（选型备选）

> 若团队更熟悉 MySQL 而非 PostgreSQL，以下为等价替代实现：

| 场景 | PostgreSQL 方案 | MySQL 等价方案 | 影响评估 |
|------|----------------|---------------|----------|
| 规则快照存储 | JSONB 列（原生 JSON 查询） | TEXT + JSON_VALID 约束，查询用 JSON_EXTRACT | 索引能力弱于 PG，阶梯区间匹配建议 PHP 侧循环遍历 |
| 预聚合表物化 | MATERIALIZED VIEW + REFRESH | 定时 INSERT SELECT 写入普通表（Cron Job） | 需额外维护定时刷新逻辑 |
| 复合索引 | 原生支持 GIN/多列 | 常规复合索引（等效） | 无明显差异 |
| 并发事务 | MVCC 更优 | InnoDB MVCC，适用 | 账务级事务需严格测试 |

### 2.2 v1.0 → v1.6 关键变更清单

| 变更项 | v1.0 旧 | v1.6 新 | 依据 |
|--------|---------|---------|------|
| 代理商等级 | 4 档固定（黄金/铂金/钻石/黑钻） | **政策体系 → 等级 两层结构**，可自由创建 | C3-2 |
| 佣金规则 | 等级默认比例 | **规则引擎浅深度**：比例 + 月度累计阶梯，代理商可个性化覆盖 | C3-2/F3 |
| 阶梯维度 | 单笔金额 | **月度累计成交金额** | Q19 |
| 历史不变性 | 快照 | **规则快照**，立即生效不影响历史 | Q20 |
| 权限 | Spatie Permission 细粒度 RBAC | **两档**（超级管理员/普通内部用户） | C3-4/Q12 |
| 状态流转 | 可配置 | **后台可配置**，当前命名为默认值（2026-07-24 修订，原"开发写死"） | Q10 |
| 提醒范围 | 术后 1/7/30 天 | **全流程**（术前/到院/术后1/7/30/90/180 + 可配置 + 自定义） | F2 |
| 提醒渠道 | 企业微信+短信+邮件 | **仅钉钉** | Q13 |
| 成交金额 | 未明 | **全人工录入，不对接外部** | C3 |
| 数据库 | MySQL | **PostgreSQL（推荐）/ MySQL**，金额 BIGINT(分) | D3 |
| 看板 | 8 图表混合 | **to B/to C 双视角**，导出 PDF/图片/HTML | C3-3/Q14 |
| 多维查询 | 任意组合 | **仅 AND 关系** | Q08 |
| 合作状态 | 未定义 | **合作中/暂停/已终止** | C2 |

### 2.3 备选技术方案

| 维度 | 主线（推荐） | 备选 1 | 备选 2 |
|------|--------------|--------|--------|
| 后端 | Laravel 13 | Node.js (NestJS) | Java Spring Boot |
| 前端 | Livewire 4 + Flux UI 2 | Vue 3 + Element Plus | React + Ant Design Pro |
| 数据库 | PostgreSQL | MySQL 8 | — |
| 图表 | ECharts | Recharts | D3.js |
| 搜索（远期） | PostgreSQL 复合索引 | Meilisearch（超百万升级） | — |

---

## 3. 各项需求的具体技术实现方案

> 下面按 7 个任务逐一拆解，给出"技术选型 → 关键组件 → 实现要点 → 验收标准"，全部对齐 v1.6 口径。

### 3.1 任务 1：现有 Excel 的系统化迁移（1 周）

#### 技术选型
- **maatwebsite/excel 3.1**（导入）
- **Laravel Migrations**（目标库表结构，金额列 BIGINT）
- **Laravel Factories + Seeders**（测试数据）
- **brick/math**（高精度金额计算，根治 `#VALUE!`）
- **Spatie Laravel Activitylog**（导入审计）

#### 关键组件
```
app/Modules/Customer/Imports/
├── CustomerImport.php        # 客户跟进表
├── AgentImport.php           # 代理商表
├── CommissionImport.php      # 推广费明细
└── SettlementImport.php      # 月结历史
app/Modules/Infra/Services/
└── ExcelMigrationService.php # 统一迁移服务（预演 + 正式）
```

#### 实现要点
1. **分步迁移策略**：上传 → 解析到 staging 临时表 → 清洗 → 业务校验 → **预演环境 100 条试运行** → 事务化写入正式表 → 错误报告 Excel
2. **根治 `#VALUE!`**：服务端用 `brick/math` 的 `BigDecimal`；**金额一律 BIGINT(分) 存储**，禁止 FLOAT/DOUBLE
3. **编号继承**：客户编号 `代理商编号-4位流水`；代理商编号全大写+横杠分隔，由「类型代码 + 简称」构成：JG=机构（旅行社/商务代理/会所/私行/商会/MCN/海外/移民中介等企业类）、GT=个体户（个人/兼职合伙人）、KR=在韩代理（在韩国有合法身份的中国合作伙伴）。**JG/GT 类**用 `简称-类型代码` 格式（如 `SZ-JG`/`ZH-GT`/`BJYM-JG`），**KR 类**用 `类型代码-简称` 格式以提高可读性（如 `KR-DY`/`KR-XY`/`KR-BJ`）。后缀建议 `XY`=企业名/`DY`=导游/`BJ`=商家/`WL`=姓氏等 2-4 位字母数字。导入时从 Excel 继承或按规则生成（DB 唯一约束 + 重试，防并发重号 D13）；**客户编号按代理商累计，不分机构**
4. **断点续传**：Redis 记录已处理行号，支持大文件分批

#### 验收标准
- 4 个 Excel 全部导入成功率 ≥ 99%
- 错误行可下载并定位到具体单元格
- 导入全过程可回滚
- 金额列无浮点误差

---

### 3.2 任务 2：客户全生命周期管理（1 周）

#### 技术选型
- **Livewire 4 + Flux UI 2**：全生命周期 CRUD
- **spatie/laravel-model-states**：5 阶段 + 7 档状态机（**状态名称与流转规则可在后台配置，当前命名为系统默认值**，2026-07-24 修订）
- **Laravel Events + Listeners**：状态变更触发副作用
- **Spatie Activitylog**：状态流转审计

#### 状态机设计（5 阶段 + 7 档，后台可配置，以下为默认值）

> **实现方案（2026-07-24 补充）**：沿用 `spatie/laravel-model-states` 包，但状态定义从 PHP 常量改为**DB 驱动**。
> - 新增 `customer_states` 表存储状态名称、阶段归属、流转白名单（JSONB/TEXT）；
> - 系统首次 migration 时插入默认值（新线索/已联系/已预约/已确认/已到院/已施术/已复购）；
> - 超级管理员在配置中心（FR-07-04）增删改状态与转移规则后，刷新 Redis 缓存即时生效；
> - 越级校验从硬编码逻辑改为读 DB 中的流转白名单；
> - 已有客户状态不受新配置影响（状态值不变，仅规则调整）。

```
5 阶段（宏观）：
  Lead（线索）→ Reserved（预约）→ Visited（到院）→ FollowUp（回访）→ Repurchased（复购）

7 档（微观状态）：
  [Lead]      L1.新线索 → L2.已联系
  [Reserved]  R1.已预约 → R2.已确认
  [Visited]   V1.已到院 → V2.已施术
  [Repurchased] B1.已复购
```

> **状态流转由客服/机构对接人手动标记触发**（Q02），系统做越级校验与留痕；**状态名称与流转规则可在后台配置**，当前 5 阶段/7 档命名与流转作为**系统默认值**（2026-07-24 修订：Q10 从"开发写死"改为"可配置，默认值"）。

#### Livewire 组件清单
- `CustomerList`（列表 + 筛选，脱敏显示）
- `CustomerCreate`（建档，必填：编号/姓名/出生日期/联系方式/意向项目/护照号/到店日期，Q01/Q24 无例外）
- `CustomerEdit`（编辑）
- `CustomerTimeline`（5 阶段时间轴）
- `CustomerDetail`（详情聚合，敏感信息按权限解密 Q07）

#### 实现要点
1. **手动标记流转**：状态变更由用户操作触发，Observer 记录
2. **不可越级**：状态机保证 L1→L2→R1→R2→V1→V2→B1
3. **每步留痕**：Activitylog 记录谁、何时、为何变更
4. **敏感信息**：护照号/手机号字段级加密存储，列表脱敏，详情按权限解密

#### 验收标准
- 状态机所有转移有测试覆盖
- 越级操作被拒绝并提示
- 时间轴展示完整生命周期
- 敏感信息列表脱敏、详情按权限

---

### 3.3 任务 3：代理商档案 + 推广费自动核算（1 周）⭐ 核心改动

#### 技术选型
- **Eloquent**：政策体系 / 等级 / 代理商 两层结构
- **Spatie Laravel Medialibrary**：代理商合同/资质文件
- **Laravel Events**：订单完成触发推广费计算（OrderCompleted）
- **brick/math**：精准金额计算（BigDecimal + BIGINT 分）
- **规则引擎（浅深度）**：配置化参数（比例 + 月度累计阶梯区间）+ DB 规则表

#### 数据模型（v1.6 两层结构）

```
policy_systems（政策体系，可自由创建）
├── id, name（如 代理商计划/合伙人计划/自定义）

policy_grades（等级，每体系下多个）
├── id, policy_system_id, name（如 黄金/铂金/钻石/黑钻、在韩合伙人）
├── threshold_cents（月业绩门槛，用于升降建议）

commission_rules（佣金规则·等级默认，浅引擎）
├── id, policy_grade_id
├── tiers (JSONB: [{min_cents, max_cents, rate}, ...])  # 月度累计阶梯区间
├── effective_at, status

agent_rule_overrides（单代理商个性化覆盖）
├── id, agent_id, tiers (JSONB), effective_at

agents（代理商）
├── id, code（**全大写+横杠**，类型代码三种 JG/GT/Kr，JG/GT 用 `简称-类型代码`、KR 用 `类型代码-简称`，唯一，永久不变）, name
├── policy_system_id, policy_grade_id
├── cooperation_status（合作中/暂停/已终止）
├── contact, cooperation_start, cooperation_end

orders（订单，成交金额人工填）
├── id, agent_id, customer_id, amount_cents (BIGINT), status

settlement_items（推广费明细，含规则快照）
├── id, settlement_id, agent_id, order_id
├── amount_cents, commission_cents
├── rule_snapshot (JSONB: 当时比例/阶梯区间/规则版本)  # 历史不变 Q20
```

#### 关键算法（月度累计阶梯）

```php
// app/Modules/Settlement/Services/CommissionCalculator.php
class CommissionCalculator
{
    // 按代理商"月度累计成交金额"匹配阶梯区间
    public function calculate(Agent $agent, string $month): BigDecimal
    {
        $monthlyTotalCents = Order::where('agent_id', $agent->id)
            ->whereMonth('completed_at', $month)
            ->sum('amount_cents');

        // 优先个性化覆盖，无则等级默认
        $rule = AgentRuleOverride::where('agent_id', $agent->id)->first()
            ?? CommissionRule::where('policy_grade_id', $agent->policy_grade_id)->first();

        $tier = $this->matchTier($rule->tiers, $monthlyTotalCents);
        $commission = BigDecimal::of($monthlyTotalCents)
            ->multipliedBy($tier['rate'])
            ->toScale(0, RoundingMode::HALF_UP); // 分

        return $commission;
    }
}
```

#### 实现要点
1. **规则读取顺序**：先读代理商个性化覆盖 → 无则读等级默认规则（C3-2）
2. **阶梯维度**：**月度累计成交金额**（Q19），不是单笔
3. **立即生效不影响历史**：比例修改立即生效，但每笔结算存**规则快照**（Q20）
4. **合作状态约束**：暂停代理商不产生新订单、不参与月结（C2）
5. **成交金额人工填**：不对接外部系统（C3），BigDecimal 计算根治 `#VALUE!`
6. **不按项目区分比例**（Q25），规则模型不加项目维度

#### 验收标准
- 政策体系/等级可自由创建，代理商可个性化覆盖
- 月度累计阶梯匹配正确
- 比例修改立即生效，历史结算不受影响（快照）
- `#VALUE!` 永久根除
- 暂停代理商不参与月结

---

### 3.4 任务 4：月结 + 结算单 + 主动跟进中心（1 周）⭐ 范围扩大

#### 技术选型
| 子功能 | 技术 |
|--------|------|
| 月结计算 | Laravel Queue + brick/math + 规则快照 |
| 结算单 Word | phpoffice/phpword（默认简洁模板，无 Logo/签章 Q22） |
| 结算单 PDF | barryvdh/laravel-dompdf |
| 跟进提醒 | Laravel Schedule + **提醒规则引擎（浅深度）** + 模板库 |
| 提醒渠道 | **钉钉 Webhook**（唯一，Q13） |

#### 关键组件
```
app/Modules/Settlement/Jobs/
├── GenerateMonthlySettlement.php     # 月结计算（队列，每代理商一个 Job）
└── GenerateSettlementDocument.php    # 文档生成
app/Modules/Reminder/
├── Models/
│   ├── ReminderRule.php              # 提醒规则（浅引擎）
│   ├── ReminderTemplate.php          # 模板库
│   └── Reminder.php                  # 提醒实例
├── Services/
│   └── ReminderScheduler.php         # 规则匹配 + 实例生成
└── Notifications/
    └── DingTalkReminder.php          # 钉钉推送
```

#### 月结：周期可自定义，默认自然月（每月 1 日 09:00）
- **触发**：Cron `schedule:run`，**结算周期与触发时间可由用户在配置中心自定义**，**默认值为自然月**每月 1 日 09:00（2026-07-24 修订：Q03 从"固定自然月"改为"可自定义，默认自然月"）
- **拆批**：按代理商拆分 Job，Redis 进度条实时显示
- **流程**：汇总上月订单 → 读规则快照 → 算推广费 → 生成 settlement + items → 派发 SettlementGenerated → 财务钉钉通知 + 审计
- **财务审核**：Livewire 页面审核 → 一键导出 Word/PDF

#### 主动跟进中心（v1.6 全流程）

| 类型 | 触发 | 说明 |
|------|------|------|
| **系统内置·术前** | 术前 N 天 / 到院前 1 天 | FR-04-13/14 |
| **系统内置·术后** | 术后 1/7/30/90/180 天 | FR-04-04/05/06/09/10 |
| **可配置·主动跟进** | 老客回访/生日/节日/营销/沉默唤醒 | FR-04-11 规则引擎 |
| **客服自定义** | 客服自行创建一次性/周期提醒 | FR-04-12 |

- **提醒目的**：提示**内部员工**主动跟进，**不发客户**（Q21），无固定话术，空白备注区
- **规则引擎浅深度**：固定触发条件类型 + 适用对象范围 + 空白备注区（F3），不做 DSL
- **模板库**：系统预置模板，客服可基于模板快速创建或完全自定义（FR-04-15）
- **执行日志**：生成/下发/完成/延期/转交全链路留痕（FR-04-16）
- **推送**：到点由 Cron 扫描 → 钉钉 Webhook

#### 实现要点
1. **订单完成一次性生成术后系列提醒实例**（1/7/30/90/180 天），到点扫描推送
2. **结算单默认简洁模板**，无需 Logo/签章素材（Q22）
3. **幂等性**：同一结算单多次生成结果一致
4. **月结进度**：Redis 实时进度条

#### 验收标准
- 1000 条数据月结 < 5 分钟（周期可自定义，默认自然月）
- Word/PDF 内容一致
- 全流程提醒准时（误差 < 1 分钟），钉钉送达
- 提醒规则可配置，客服可自定义
- 失败重试 3 次

---

### 3.5 任务 5：多维查询（0.5 周）

#### 技术选型
- **Spatie Laravel Query Builder**：9 维度 `?filter[xxx]=`
- **PostgreSQL 复合索引**（主）/ **Meilisearch**（远期超百万升级）
- **Livewire PowerGrid**：结果展示 + 分页

#### 9 维度实现（仅 AND，Q08）
```
日期 / 时间 / 客户 / 代理商 / 施术项目 / 机构 / 翻译 / 金额(区间) / 护照号(模糊)
```

#### 关键代码模式
```php
// app/Modules/Customer/Livewire/MultiDimensionalSearch.php
class MultiDimensionalSearch extends Component
{
    public array $filters = [
        'date_from' => null, 'date_to' => null,
        'customer_id' => null, 'agent_id' => null,
        'procedure_id' => null, 'organization_id' => null,
        'translator_id' => null, 'amount_min' => null, 'amount_max' => null,
        'passport_no' => null,
    ];

    public function render()
    {
        // 仅 AND 关系组合（Q08），不支持 OR
        $query = Customer::query()->with(['agent', 'organization']);
        foreach ($this->filters as $field => $value) {
            if (!is_null($value) && $value !== '') {
                $query->when($this->getFilterMethod($field),
                    fn($q) => $q->{$this->getFilterMethod($field)}($value));
            }
        }
        return view('livewire.multi-dimensional-search', [
            'results' => $query->paginate(50),
        ]);
    }
}
```

#### 性能优化
1. **复合索引**：`(agent_id, procedure_id, settled_at)` 等常用组合
2. **护照号**：加密存储 + 前缀索引 + 业务层解密匹配
3. **数据权限不限制**：所有内部用户可查全部（Q12）

#### 验收标准
- 9 维度可任意组合（AND）
- 100 万数据下查询 < 1 秒
- 支持结果导出 Excel

---

### 3.6 任务 6：首页数据看板 + 月度图表（1 周）

#### 技术选型
- **ECharts 5.5+**：to B / to C 双视角图表
- **Livewire 实时刷新**（30 秒轮询）
- **PostgreSQL 聚合查询 + 索引 + 按需短缓存**
- **html2canvas / dompdf**：导出 PDF / 图片(PNG) / HTML（Q14）

#### 6 项核心数据
1. 今日新增客户　2. 今日成交金额　3. 本月营收　4. 在跟进客户数　5. 待回访客户数　6. 待结算金额

#### to B / to C 双视角图表（C3-3）
| 视角 | 图表 |
|------|------|
| **to B（代理商侧）** | 代理商月度推广费排行 / 月度推广费趋势 / 等级分布 |
| **to C（客户侧）** | 客户来源分布 / 月度消费趋势 / 复购率 / 跟进完成率 |

#### 性能优化
- 首版使用 PostgreSQL 聚合查询和针对性索引，并记录真实数据下的查询耗时
- 仅在普通聚合和短缓存无法满足 < 2 秒目标时，引入 `daily_agent_metric` /
  `monthly_report` 预聚合表及其重建、一致性检查
- **Redis 缓存**：按测量结果启用短 TTL，不作为正确性依赖
- **数据权限不限制**：所有内部用户看全量（Q12）

#### 导出（Q14）
- 支持将当前看板导出为 **PDF / 图片(PNG) / HTML** 三种格式，队列生成文件

#### 验收标准
- 看板首屏 < 2 秒
- to B / to C 图表联动筛选
- 支持时间范围切换
- 支持导出 PDF / 图片 / HTML

---

### 3.7 任务 7：系统配置中心（0.5 周）

#### 技术选型
- **Livewire CRUD** + **FluxUI Tabs**
- **Laravel Cache**（配置项热更新，改完即刷 Redis）

#### 配置模块
```
1. Agent：政策体系、等级与代理商类型
2. Settlement：佣金规则、覆盖规则与结算周期
3. Customer：状态名称与流转规则
4. Auth：用户与两档权限
5. Config：全局系统参数和上述领域配置的统一管理入口
```

#### 关键设计
- 统一配置页面调用数据所属领域的应用用例，不直接写入其他模块的数据表
- **政策体系/等级**归 Agent，**佣金规则**归 Settlement，**客户状态**归 Customer
- **用户管理**归 Auth；`is_super_admin` 布尔由中间件控制菜单/按钮可见性
- 各领域自行决定缓存与失效策略；Redis 缓存不改变数据所有权

#### 验收标准
- 统一入口可完成政策体系、等级、规则、状态和用户管理
- 所有写操作由数据所属模块执行
- 配置变更实时生效（缓存失效）
- 操作有审计日志

---

## 4. 分阶段技术路线（按开发优先级）

> **总周期：10–12 周**（含工程化，比原 6 周增加 4–6 周基础建设）
> 分为 8 个阶段（Phase 0–7），每阶段独立可交付。架构基线：模块化单体。

### Phase 0：项目准备（0.5 周）

**目标**：环境、仓库、规范就绪

| 工作项 | 技术/工具 | 交付物 |
|--------|-----------|--------|
| 仓库初始化 | Git + .gitignore + README | GitHub 仓库 |
| 分支模型 | Git Flow（main/develop/feature） | 分支规范文档 |
| 代码规范 | Laravel Pint + PHPStan level 6 | 配置文件 |
| 编辑器规范 | `.editorconfig` + VSCode 共享配置 | 配置文件 |
| 文档规范 | Markdown 模板（需求/架构/技术/API） | `/docs` 目录 |

**关键里程碑 M0**：✅ 仓库可克隆，所有协作者环境一致

---

### Phase 1：基础架构搭建（1.5 周）

**目标**：模块化单体骨架 + Docker + CI/CD 可跑通

| 工作项 | 技术/工具 | 关键点 |
|--------|-----------|--------|
| Docker 化 | Docker Compose | nginx + php-fpm + redis + postgres 一键启动 |
| Laravel 初始化 | Laravel 13 + Pint | 安装核心包，**模块化目录结构** `app/Modules/{Domain}/` |
| Livewire + FluxUI | composer require | 配置 Light/Dark 主题 |
| 认证脚手架 | Laravel Breeze + **两档权限中间件** | 超级管理员 / 普通内部用户（C3-4） |
| CI/CD | GitHub Actions | PR 触发 PHPUnit + PHPStan + Pint，main 触发部署 |
| 队列与定时 | Supervisor + Laravel Schedule | Horizon（可选） |
| 文件存储 | 本地 / 对象存储 | 公开/私有 bucket |
| 备份策略 | spatie/laravel-backup | PostgreSQL 每日全量 + WAL，RPO≤24h |
| 监控 | Sentry 接入 | 环境区分 |

**关键里程碑 M1**：✅ `docker compose up` 启动，登录页可访问，CI 跑通

**依赖关系**：无前置依赖，是所有后续阶段的基础

---

### Phase 2：数据层与 Excel 导入（1.5 周）

**状态（2026-07-27）**：开发交付已结束；真实历史迁移转为上线验收项。

**目标**：数据库结构稳定，历史文件具备安全、可审计、可回滚的迁移能力

| 工作项 | 技术/工具 | 输出 |
|--------|-----------|------|
| Migration | Laravel Migrations | 核心关系表、唯一约束、渠道互斥和金额/费率约束 |
| 模块协作 | Application Contracts/Data | 数据所有者单写，DataImport 同步协调 |
| 模拟数据 | 确定性 Seeder | 代理商、客户、跨渠道订单、推广费、月结和跟进 |
| Excel/CSV 导入 | PhpSpreadsheet + brick/math | XLSX/XLS/CSV、UTF-8/GB18030、精确金额 |
| 导入 UI | Livewire + FluxUI | 加密上传、前 50 行预览、映射、裁决和错误报告 |
| 预演环境 | staging + Database Transaction | 最多 100 条强制回滚试运行 |
| 正式提交/回滚 | PostgreSQL Transaction | 整批原子提交、24 小时逆序回滚及修改阻断 |
| 审计 | spatie/laravel-activitylog | 导入、人工裁决和回滚记录 |

**开发里程碑 M2-D（已完成）**：✅ 导入能力、错误报告、原子性、回滚和金额精度
通过自动化测试；脱敏结构样本复算通过。

**上线里程碑 M2-O（待执行）**：真实历史文件全量导入，所有差异完成业务裁决并
抽样核对。未完成前不得宣称生产历史数据迁移完成。

**依赖关系**：依赖 Phase 1

---

### Phase 3：客户全生命周期（任务 2）（1 周）

| 工作项 | 技术 |
|--------|------|
| 状态机 | Customer 应用服务 + 数据库状态转移规则（后台可配置 Q10） |
| Livewire 组件 | CustomerList/Create/Edit/Timeline/Detail |
| 手动标记流转 | Observer + Event（Q02） |
| 5 阶段可视化 | FluxUI Timeline |
| 敏感信息 | 字段级加密 + 脱敏（Q07） |
| 操作审计 | Activitylog |

**关键里程碑 M3**：✅ 客户可建档、状态手动流转、时间轴可看、敏感信息脱敏

**依赖关系**：依赖 Phase 2

---

### Phase 4：代理商 + 政策体系 + 推广费核算（任务 3）（1 周）⭐

| 工作项 | 技术 |
|--------|------|
| 政策体系/等级 CRUD | Livewire + Eloquent（两层结构） |
| 佣金规则引擎（浅） | commission_rules + agent_rule_overrides，JSONB 阶梯 |
| 推广费算法 | CommissionCalculator + brick/math（月度累计阶梯） |
| 规则快照 | settlement_items.rule_snapshot（Q20） |
| 合作状态 | 合作中/暂停/已终止约束（C2） |
| 单元测试 | PHPUnit 12 |

**关键里程碑 M4**：✅ 政策体系两层可用，规则引擎配置化，推广费按月度累计阶梯自动算，`#VALUE!` 根除

**依赖关系**：依赖 Phase 3（客户存在才能产生订单）

---

### Phase 5：月结 + 结算单 + 主动跟进中心（任务 4）（1.5 周）⭐

| 工作项 | 技术 |
|--------|------|
| 月结计算 | Laravel Queue + Redis 进度（**周期可自定义，默认自然月**，每月 1 日 09:00） |
| 结算单 Word/PDF | phpword + dompdf（默认简洁模板，无 Logo/签章 Q22） |
| 主动跟进规则引擎 | ReminderRule + 模板库（浅深度 F3） |
| 全流程提醒 | 术前/到院/术后1/7/30/90/180 + 可配置 + 客服自定义（F2） |
| 钉钉推送 | DingTalkReminder Webhook（Q13） |
| 提醒执行日志 | 全链路留痕（FR-04-16） |
| 提醒中心 UI | Livewire + 站内待办 |

**关键里程碑 M5**：✅ 1000 条月结 < 5 分钟，全流程提醒准时，钉钉送达

**依赖关系**：依赖 Phase 4

---

### Phase 6：多维查询 + 数据看板 + 配置中心（任务 5/6/7）（1.5 周）

| 工作项 | 技术 |
|--------|------|
| 多维查询 | Spatie Query Builder（仅 AND，Q08） |
| 查询性能 | 复合索引 + 缓存 |
| to B/to C 看板 | ECharts + PostgreSQL 聚合查询；达到性能阈值后再引入预聚合 |
| 看板导出 | PDF / 图片(PNG) / HTML（Q14） |
| 配置中心 | 聚合 Agent/Settlement/Customer/Auth 的管理用例，并管理全局系统参数 |

**关键里程碑 M6**：✅ 9 维度 AND 查询，看板 < 2 秒，导出三格式，配置热更新

**依赖关系**：依赖 Phase 5

---

### Phase 7：集成测试 + 性能优化 + 部署上线（1.5 周）

| 工作项 | 技术 |
|--------|------|
| E2E 测试 | Laravel Dusk / Playwright |
| 性能压测 | k6 / Apache Bench（目标：100 并发 < 2 秒） |
| 安全审计 | OWASP ZAP + 人工审计 |
| 数据库优化 | 慢查询日志 + 索引调优 |
| 前端优化 | 关键 CSS 内联 + 图片懒加载 + Three.js 降级 |
| Redis 故障演练 | 验证失败可观测、任务重试与恢复流程，不动态切换为 sync |
| 备份灾恢演练 | WAL 时间点恢复 + RTO 验证 |
| 生产部署 | 蓝绿部署 / 灰度发布 |
| 数据迁移 | 真实环境全量迁移 + 校验 |
| 用户培训 | 操作手册 + 视频教程 |
| 监控告警 | Sentry + 钉钉告警 |
| 文档交付 | API 文档 + 部署文档 + 运维手册 |

**关键里程碑 M7**：✅ 系统上线，监控告警就位，备份灾恢验证，团队可独立运维

**依赖关系**：依赖 Phase 6

---

### 阶段依赖关系图

```
Phase 0 (准备)
   ↓
Phase 1 (基础架构·模块化单体)
   ↓
Phase 2 (数据层 + Excel 导入)
   ↓
Phase 3 (客户生命周期) ──┐
   ↓                     │
Phase 4 (政策体系+推广费) ┤
   ↓                     │
Phase 5 (月结+主动跟进) ──┤
   ↓                     │
Phase 6 (查询+看板+配置) ─┤
   ↓                     │
Phase 7 (测试+部署) ←────┘
```

---

## 5. 关键里程碑总览

| 里程碑 | 名称 | 完成标志 | 周次 |
|--------|------|----------|------|
| **M0** | 项目就绪 | 仓库可克隆，团队环境一致 | 0.5 |
| **M1** | 基础架构 | `docker compose up` 一键启动 + CI 跑通 | 2 |
| **M2** | 数据迁移 | 4 个 Excel 100% 导入，金额无浮点 | 3.5 |
| **M3** | 客户生命周期 | 建档→复购 5 阶段手动流转，脱敏 | 4.5 |
| **M4** | 政策体系+核算 | 两层结构+规则引擎+月度累计阶梯，`#VALUE!` 根除 | 5.5 |
| **M5** | 月结+主动跟进 | 月结 < 5 分钟，全流程提醒+钉钉 | 7 |
| **M6** | 查询看板配置 | 9 维 AND 查询，看板 < 2 秒，导出三格式 | 8.5 |
| **M7** | 正式上线 | 监控就位，备份灾恢验证，UAT 通过 | 10 |

---

## 6. 风险评估与应对策略

| 风险 | 等级 | 应对措施 |
|------|------|----------|
| Excel 数据脏（空值、格式错乱） | 🔴 高 | Phase 2 引入"预演环境"，先 100 条测试再全量 |
| 浮点精度引发 `#VALUE!` | 🔴 高 | 全程 `brick/math` BigDecimal + **BIGINT(分) 存储** |
| 政策体系/规则配置错误 | 🔴 高 | 规则保存前强校验（区间不重叠、比例≤100%）+ 试算预览 |
| 历史结算数据重构引发纠纷 | 🟡 中 | 规则快照 + 新系统双轨并行 1 个月 |
| 性能不达标（10 万+ 数据） | 🟡 中 | 提前在 Phase 6 压测；预留 Meilisearch 升级路径 |
| Redis 单点故障 | 🟡 中 | 缓存按用例显式绕过；队列失败告警、重试并由运维恢复 |
| 团队不熟悉 Livewire | 🟡 中 | Phase 0–1 提供 1 周培训 + Code Review 严格 |
| 跟进规则配置复杂度上升 | 🟢 低 | 规则引擎首期浅深度，预留升级至"中"（条件模型） |
| 需求变更（医美业务季节性强） | 🟢 低 | 政策体系/规则引擎/提醒规则可配置，预留扩展点 |

---

## 7. 团队配置与协作建议

### 7.1 推荐团队（2–3 人小团队）

| 角色 | 人数 | 核心职责 |
|------|------|----------|
| 全栈工程师（主程） | 1 | 架构、核心模块（政策体系/规则引擎/月结）、Code Review |
| 全栈工程师 | 1 | 业务模块（客户/提醒/查询）、Excel 导入、测试 |
| UI/前端（兼） | 0.5 | FluxUI 调优、ECharts 图表、Three.js Hero、响应式 |

> 如果时间紧可加 1 名测试工程师（外包也可）

### 7.1b Solo 开发者路径（1 人）（2026-07-24 评审 Q4）

> **实际开发团队为 1 人**，总周期延至 **16–20 周**。以下为缩减方案：

| 调整项 | 2–3 人方案 | Solo 方案 |
|--------|-----------|-----------|
| 总周期 | 10–12 周 | **16–20 周** |
| Phase 3–6 | 前后阶段可有限并行 | **严格串行**，上一阶段完测才进下一阶段 |
| Three.js Hero | Phase 1 引入 | **砍掉**，首版用纯 FluxUI 动画替代，降低复杂度 |
| 看板导出 PNG | Phase 6 | **砍掉**，首版仅导出 PDF + HTML，PNG 迭代补充 |
| E2E 测试 | Laravel Dusk | **砍掉**，首版仅 PHPUnit Unit + Feature，手工回归 |
| 用户培训 | 视频教程 | **砍掉**，首版仅操作手册（Markdown） |
| 代码审查 | PR Review | **自查** + PHPStan + Pint 自动门禁 |
| M1 | 2 周 | 2 周（不变，基础架构必须扎实） |
| M2 | 3.5 周 | 5 周 |
| M3 | 4.5 周 | 7 周 |
| M4 | 5.5 周 | 10 周 |
| M5 | 7 周 | 12 周 |
| M6 | 8.5 周 | 15 周 |
| M7 | 10 周 | 18 周 |

> Solo 模式下优先保证核心模块（Phase 2-5）质量，Phase 6 的导出三格式缩为两格式、Phase 7 精简为 PHPUnit + 压测 + 备份就上线。

### 7.2 协作工具

- **项目管理**：TAPD / 飞书项目
- **代码托管**：GitHub / GitLab
- **CI/CD**：GitHub Actions
- **文档**：语雀 / Notion / Markdown in repo
- **即时沟通**：钉钉（与推送渠道统一）
- **设计稿**：MasterGo / Figma

### 7.3 每周交付节奏

- **周一**：周计划 + 上周回顾
- **周三**：中期检查
- **周五**：演示 + 部署到预演环境
- **每周**：必须有一个可演示的增量

---

## 8. 附录：参考资料与备选方案

### 8.1 关键官方文档

- Laravel 13：https://laravel.com/docs/13.x
- Livewire 4：https://livewire.laravel.com/docs
- FluxUI：https://fluxui.dev
- Spatie Laravel Model States：https://spatie.be/docs/laravel-model-states
- Spatie Laravel Activitylog：https://spatie.be/docs/laravel-activitylog
- brick/math：https://github.com/brick/math
- maatwebsite/excel：https://laravel-excel.com

### 8.2 关联文档

- 《CRM-需求文档-v1.8.md》—— 需求源头（46 项功能需求）
- 《CRM-系统架构设计.md》v1.2 —— 架构基线（模块化单体、分层、9 模块）
- 《需求沟通与完善指南.md》—— 需求沟通方法论

### 8.3 备选方案速查

| 场景 | 备选 |
|------|------|
| 团队熟悉 Vue | Vue 3 + Element Plus + Laravel API |
| 数据量 > 100 万 | Meilisearch 替换 PostgreSQL 模糊查询 |
| 规则复杂度上升 | 规则引擎由"浅"升级至"中"（条件模型） |
| 需独立部署报表 | Report 模块抽离为微服务（架构 D1 退路） |

---

## 9. 基于当前 develop 的增量路线（2026-08-14）

> 规划基线：develop 提交 7d4c4a65f6e813b000fcc930c8bfa1280a687e58。
> 本节是针对当前已完成 Phase 2–6、订单中心和月结工作流优化后的后续规划，属于
> 设计输入，不表示对应能力已经实现。实际状态仍以代码、迁移、测试和
> docs/project-status.md 为准。
>
> 本节与前文历史 Phase 路线发生冲突时，以本节的现行增量口径为准。例如，前文
> Phase 5 表格中的“每月 1 日生成”是历史规划描述；本节将统计周期和生成日期拆开，
> 规划为自然月统计、每月 10 日生成上一个自然月。

### 9.1 总体拆分与实施原则

本轮需求不建立一个承载全部改动的 feature/new-requirements 大分支，拆为 7 个
独立 PR。这样可以让高风险数据模型清理、财务调度规则和低风险界面优化分别验收，
也避免已经存在的能力被重复实现。

| PR | 内容 | 建议分支 | 数据库迁移 | 改动等级 | 主要风险 |
|---|---|---|---|---|---|
| PR-1 | 完整移除直销业务 | feature/remove-direct-sales | 需要 forward migration | 大 | 高 |
| PR-2 | 客户状态追踪树及“关联客户”命名 | feature/customer-status-tree | 不需要 | 中小 | 低 |
| PR-3 | 主动提醒 UI 紧凑化 | feature/reminder-compact-ui | 不需要 | 小 | 低 |
| PR-4 | 指定节假日客服提醒 | feature/holiday-customer-reminders | 大概率不需要 | 中 | 中 |
| PR-5 | 总览页面数据下钻 | feature/dashboard-drilldown | 不需要 | 中 | 中低 |
| PR-6 | 自然月统计及每月 10 日生成 | feature/settlement-generation-schedule | 建议需要 | 中大 | 高 |
| PR-7 | 月结默认周期、日级查询和下载闭环 | feature/settlement-period-navigation | 不需要或极小 | 中 | 中 |

预计为 5–8 个有效开发日，不包含 UAT 人工验收及返修。工作量主要集中在 PR-1 的
跨模块清理和 PR-6 的月结时间语义拆分。每个 PR 都从 develop 创建，完成定向测试
后再执行完整质量门禁，目标为合入 develop；不要等 7 个 PR 全部完成后才开始验证。

实施时继续遵守以下基线：

- 跨模块只通过当前已接受的 Application Contract/Data 协作，不直接引用其他模块的
  Model、具体 Service、Infrastructure、Presentation 或数据表。
- 数据库结构只通过新增 migration 演进，不修改已经提交的旧 migration。
- 不新增 RBAC、权限表、领域事件、CQRS、预聚合表或新的通用架构层。
- 完整业务页面按现有页面层级规则提供明确的父级命名路由返回按钮，并在 Feature
  测试中同时断言按钮文字和目标路由。
- 规划中的“已存在”能力必须先以回归测试确认，不能因为需求名称相近而再建一套。

### 9.2 PR-1：完整移除直销业务

这是本轮的基础清理，必须先于依赖客户、订单和报表筛选的后续工作。当前直销逻辑
已经进入 Customer、Order、DataImport、Report、Audit、Config、导航、Seeder 和多组
Feature 测试，因此不能只隐藏一个菜单入口。

#### 目标范围

- Customer 的来源模型收敛为 source_agent_id。移除 original_channel、
  source_direct_sales_id、DirectSalesSource 及客户表单中的 agent/direct 二选一；
  客户编号统一采用代理商编号体系。
- Order 收敛为 agent_id，移除 channel 和 direct_sales_source_id，同步清理列表筛选、
  创建、编辑、详情、回收站、校验和相关 Data/Contract 字段。
- 删除 DirectSalesSourceConfiguration、DirectSalesSourceManager、对应 Blade 页面、
  /admin/direct-sales-sources 路由、配置中心入口以及中韩文翻译。
- 清理 DataImport 的直销来源字段、模板列、基础配置工作表、引用就绪检查和解析
  分支。新模板不再出现“直销来源”“渠道 = direct”“直销来源编号”。
- 检查 Report、Audit、导航、Seeder、工厂和所有测试中的直销字段与分支；删除有效
  业务代码中的直销语义，旧 migration 和明确的历史说明可保留。
- 删除 direct 订单分支后，所有合法订单必须经过代理商推广费计算路径；不得留下
  agent_id = null 但仍可保存的订单。

#### 数据安全门禁

新增 forward migration，顺序规划如下：

1. 在实际执行环境中显式检查 customers.original_channel = 'direct' 和
   orders.channel = 'direct' 的记录。
2. 若发现任何真实业务记录，直接阻断 migration，不做静默转换或删除。
3. 确认无记录后删除外键和索引，再删除客户、订单直销字段，最后删除
   direct_sales_sources 表。

不得把此改造做成软下线、兼容层或历史投影表。Production/UAT 的实际数据检查仍是
上线前置条件，不能由本机测试替代。

#### 验收与回归

重点回归 PhaseTwoDataModelTest、OrderManagementTest、PhaseFourAgentCommissionTest、
PhaseFiveReminderTest、PhaseSixReportingConfigurationTest、ReferenceConfigurationImportTest、
历史导入测试、ConfigurationNavigationTest 和 ModuleBoundaryTest。测试 fixture
统一改为代理商数据。

全仓库搜索以下标识，除旧 migration 和明确历史文档外，不应再有有效业务代码：

    DirectSalesSource
    direct_sales
    directSales
    source_direct_sales_id
    direct_sales_source_id
    channel === 'direct'

### 9.3 PR-2：客户状态追踪树及“关联客户”命名

Customer 当前已经有生命周期阶段、状态、sort_order、CustomerStatusTransition 和
to_status_ids，配置中心也已支持管理员维护允许的流转关系。因此本 PR 不新建状态树表
或第二套状态模型。

计划在 Customer 模块内部增加只读的状态图查询，例如：

    CustomerDirectory::statusGraph($customerId)

返回 stages、statuses、transitions 和 current_status_id，直接读取现有状态及流转
数据。CustomerDetail 展示“客户状态追踪”：已经过的节点弱强调、当前节点强强调、
可继续流转节点正常显示、不可达或停用节点弱化；不展示经过时间，状态修改仍走现有
表单，追踪树本身不可编辑。

Agent 详情中的 agents.detail.customers 只做中韩文案同步，把“来源客户”改为“关联客户”，
不改变 Agent 与 Customer 的数据关系。

本 PR 不需要 migration。若新增完整页面或层级入口，沿用 page-back、命名父路由、
wire:navigate 和 Feature 导航断言。

### 9.4 PR-3：主动提醒 UI 紧凑化

这是 Presentation 层重排，不改变 Reminder 的业务动作或 ReminderWorkspace。默认卡片
只展示标题、状态、客户/时间、摘要和“完成/延期/转交/关闭”操作；延期、转交、完成、
关闭的备注或表单只在点击对应动作时展开。

当前卡片共享 actionNotes、snoozeUntil、snoozeReason、assigneeId。计划增加
activeReminderId 和 actionMode，保证同一时间只有正在操作的提醒展开编辑区。
complete、snooze、transfer、cancel、retryNotification 的业务行为、权限、校验和审计
保持不变；不引入新的 JavaScript 框架。

### 9.5 PR-4：指定节假日客服提醒

到店前一天和到店当天的提醒已经由现有 ReminderScheduler 生成（当前还包含术前其他
节点），并沿用预约 ownerId 分配。因此本 PR 不重复开发第二套到店提醒，只调整文案
使其明确“客服联系客户”，并增加回归测试确保 -1 和 0 两条持续存在，避免同一天产生
重复提醒。

真正新增的是指定日期触发：

- 复用 ReminderRule 现有的 trigger_type、trigger_config、scope_type 和 scope_config
  JSON，不新建 holiday 表。
- 增加 holiday_date 触发类型，配置单个日期和时间，例如
  {"date":"2026-09-25","time":"09:00"}；多日节假日使用多条规则。
- 配置中心提供规则名称、指定日期、时间、现有范围、标题和建议内容；继续复用
  all_customers、agent、project、owner、cooperation_status 范围。
- Scheduler 仍沿用 Customer.ownerId → Reminder.assigned_to，不建立第二套客服负责人
  体系。
- 沿用稳定 dedupe_key，建议格式为 holiday-rule:{ruleId}:{customerId}:{date}，并覆盖
  重复扫描、跨日和停用规则。

### 9.6 PR-5：总览页面数据下钻

Dashboard 的下钻必须同时传递当前筛选时间范围，不能只给展示卡片套链接。建议映射
如下：

| 总览内容 | 目标 | 约束 |
|---|---|---|
| 营业额 | 多维查询 | 带当前 completedFrom/completedTo |
| 新客户 | 客户列表 | 增加并传递 createdFrom/createdTo |
| 待处理提醒、今日任务 | 主动提醒 | 复用现有权限与筛选 |
| 推广费 | 代理商/月结明细 | 仅超级管理员可进入受限目标 |
| 复购率、月度收入趋势 | 多维查询或订单查询 | 带对应时间范围 |
| 代理商排行、最近客户 | 对应详情页 | 使用稳定命名路由 |
| 月结进度 | 当前月结周期 | 仅超级管理员可进入月结中心 |

CustomerList 增加 createdFrom、createdTo，ReportSearchPage 继续使用现有 query string
筛选字段。DashboardService 继续通过 Report Contracts 聚合，不直接跨表。任何下钻
都不得绕过普通内部用户对代理商、月结和配置中心的现有权限；目标为二级或三级完整
页面时补齐父级返回按钮和导航 Feature 断言。

### 9.7 PR-6：自然月统计及每月 10 日生成

这是财务核心改造，必须独立 PR，并在实现前单独确认旧配置历史与现有批次兼容策略。
当前 SettlementPeriodCalculator 将 boundary_day 同时用于周期边界和到期判断，
SettlementRunManager::startIfDue() 会寻找最新已闭合周期。因此不能简单把 boundary_day
从 1 改为 10，否则会把周期变成“当月 10 日至次月 9 日”。

#### 新语义

- 统计周期永远是自然月：period_start = 月初，period_end = 月末。
- 生成日期为每月 10 日指定时间，生成上一个自然月；现有 trigger_time 可继续作为
  生成时间。
- 生成检查仍每分钟运行，但 startIfDue() 只有到达生成日和时间后才把上一个自然月
  视为 due。
- 保留 scheduler compensation：若 10 日窗口停机，后续恢复时在目标月份仍无批次的
  情况下补生成；补偿不得生成重复 Run。

配置上拟新增版本化的 settlement_configurations.generation_day，默认值为 10，保留
boundary_day 供历史配置和旧周期重建使用。配置中心改为展示“结算周期：自然月、生成日：
每月 10 日、生成时间：09:00”；若生成日不是业务可配置项，UI 可只读。不得通过重解释
旧字段来改变历史配置含义。

必须覆盖以下时间点与不变量：

    9月9日 23:59       不生成 8 月
    9月10日 08:59      不生成 8 月
    9月10日 09:00      生成 8 月 1 日～8 月 31 日
    9月10日再次执行    不重复生成
    9月11日仍无批次    补偿生成 8 月
    10月10日           生成 9 月 1 日～9 月 30 日

同时保留同周期唯一 Run、同代理商/周期唯一 Settlement、失败可重试、已有 Run 不重复、
历史周期重建和批次成员一致性。需要新增 migration 时必须先在 UAT/Production 核对
配置历史与真实批次，再按可回退、可审计的 forward migration 执行；不得改写旧 migration。

### 9.8 PR-7：月结页面体验与查询闭环

本 PR 不再修改月结计算核心，等 PR-6 稳定后单独处理页面和回归覆盖。

1. SettlementCenter 增加 selectedPeriodEnd。进入页面时读取最新已生成的 SettlementRun，
   默认只展示该周期；顶部提供已生成周期下拉，9 月 5 日默认 7 月，9 月 10 日 8 月批次
   生成后默认 8 月。
2. SettlementHistory 从 input type="month" 改为现有 localized date picker 的业务日期
   起止查询。查询采用周期重叠语义：period_start <= businessTo AND period_end >= businessFrom，
   不按 created_at、generated_at 或 reviewed_at 查询。
3. 增加“生成 → approved → 文档存在 → settled → 重新进入详情”的回归测试，确认 PDF、
   Word 和其他既有格式在已结算后仍可下载。现有文档是在 approve 时生成，settle() 不应
   删除文档，因此优先补测试，不重新设计下载系统。
4. 若旧历史记录只有 reconciled 状态而没有明细或快照，不凭空重建正式月结文件；先把
   缺失证据作为 UAT/历史数据验收问题处理。

### 9.9 推荐实施顺序、质量门禁与 UAT 分轮

推荐顺序为：

    PR-1 直销清理
      ↓
    PR-2 客户状态追踪树
      ↓
    PR-3 提醒紧凑化
      ↓
    PR-4 指定节假日提醒
      ↓
    PR-5 Dashboard 下钻
      ↓
    PR-6 月结生成时序
      ↓
    PR-7 月结页面闭环

每个 PR 的计划交付门禁为：从 develop 创建 feature 分支，先执行相关的
docker compose exec app php scripts/run-tests.php <测试文件或 --filter=...>，再执行：

    docker compose exec app composer ci:check
    docker compose exec vite npm run build

本节仅作规划记录，本次写入不执行上述功能开发或质量门禁。PR-1 和 PR-6 在进入
完整门禁前应分别完成一次数据安全/财务规则的独立审查。

UAT 建议拆为两轮不可变 RC：

- 第一轮业务/UI RC：PR-1 至 PR-5。重点验收客户建档、订单创建、代理商客户关联、
  状态流转、提醒生成及动作、Dashboard 跳转、中韩文和导入模板。
- 第二轮月结 RC：PR-6 和 PR-7。重点验收 10 日生成上月、默认最新周期、业务日期
  日级查询、已结算文档下载、补偿生成、失败重试和不重复。

RC 失败时发布下一个递增标签，不删除、移动或复用失败标签。Production/UAT 的真实
数据检查、历史导入、抽样核对和财务人工验收仍是部署前置条件，本机文档校验不能替代。

### 9.10 明确不在本轮范围内

- 不新增 RBAC、权限表或第二套客服负责人机制。
- 客户状态树只展示当前结构和节点，不做历史轨迹可视化，也不从树上修改状态。
- 不重新开发已经存在的到店前一天、到店当天提醒，不接第三方节假日 API。
- 直销不做兼容层、软下线或历史投影表；确认目标环境无真实数据后从正式业务模型退出。
- 不通过 boundary_day = 10 实现 10 号月结，不把周期改成 10 日至次月 9 日。
- 不借机重构 Customer、Reminder、Settlement 模块，不引入领域事件、CQRS 或预聚合。

---

## 文档结束

> 下一步建议：
> 1. 先评审本节 7 个 PR 的边界、PR-1 的真实数据门禁和 PR-6 的历史配置兼容策略。
> 2. 在目标 UAT/Production 执行只读数据核对，确认直销记录为空，并确认月结配置、批次和文档证据。
> 3. 按 9.9 的顺序逐个建立 feature 分支；每个 PR 完成定向测试和完整本地门禁后再进入集成。
> 4. 功能实际落地后，再按 docs/project-status.md 的规则更新已实现/尚未实现状态；本规划不替代状态文档。

> **下一步建议**：
> 1. 评审本文档 + 《系统架构设计》，确认技术选型（PostgreSQL vs MySQL）
> 2. 召开 kickoff 会议，对齐里程碑
> 3. Phase 0 启动，仓库初始化
> 4. 剩余 4 项低/中优先级待确认问题（Q11/Q15/Q17/Q18）可在开发启动后迭代明确，不阻塞 MVP

**版本历史**：
- v1.0（2026-07-23）：初版，基于需求文档 v1.0（4 档等级 + 硬编码口径）
- v1.6（2026-07-24）：对齐需求文档 v1.6 + 架构设计 v1.1；政策体系两层结构、规则引擎浅深度、月度累计阶梯、规则快照、合作状态、成交金额人工填、跟进全流程+钉钉、两档权限、状态流转写死、PG/MySQL+BIGINT分、to B/to C看板+导出三格式、多维查询仅AND、模块化单体+领域事件+轻量CQRS+Redis降级+备份灾恢
- v1.7（2026-07-24）：用户修订：Q03 月结触发→可自定义周期(默认自然月)，Q10 状态流转→后台可配置(当前命名为默认值)；同步修订 1.1/1.2/2.2/3.2/3.4/3.7/Phase 3/Phase 5
- v1.8（2026-07-24）：评审反馈落档：新增 Solo 开发者路径(7.1b, 16-20周)；新增 MySQL 替代方案(2.1.9)；补充状态机可配置实现方案(3.2)；升级架构文档到 v1.2；跨文档统一版本号 v1.8
- v1.9（2026-07-24）：需求 v1.9 同步：编号体系统一(简称-类型代码，KR→DY-KR)；新增配置版本回滚(FR-07-08, config_snapshots 表)；新增类型代码自定义(FR-07-09, agent_type_codes 表)；架构文档新增 2 张核心表
- v2.0（2026-08-14）：基于 develop 提交 7d4c4a65f6e813b000fcc930c8bfa1280a687e58 追加现行增量路线；拆分 7 个独立 PR，明确直销移除、客户状态追踪、提醒体验、节假日规则、Dashboard 下钻、自然月月结生成和月结查询闭环的依赖、数据门禁、测试与 UAT 分轮。
