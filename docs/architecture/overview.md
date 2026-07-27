# 当前架构概览

> 最后核验：2026-07-27  
> 本文只描述当前仓库已经采用的架构，不描述未来业务数据流。

## 系统形态

GN-System 是单一 Laravel 应用和单一部署单元，业务代码按领域目录组织为模块化
单体。它不是微服务系统，也没有独立 SPA 或对外业务 API。

当前主要组成：

- PHP 8.3、Laravel 13、Laravel Fortify
- Livewire 4、Flux UI 2、Tailwind CSS 4、Alpine.js
- PostgreSQL 16 作为唯一当前主数据库
- Redis 7 用于缓存和队列
- 开发环境由 Nginx、PHP-FPM、Queue、Scheduler 和 Vite 独立容器组成
- 生产环境由 TLS Nginx、PHP-FPM、Queue、Scheduler、PostgreSQL 和 Redis 组成

数据库已经在代码、Compose 和 CI 中确定为 PostgreSQL。源设计文档中保留的
MySQL 内容只是历史备选，不是当前支持矩阵。

## 代码布局

- `app/Modules/`：Auth、核心业务模块和 DataImport 协调模块
- `app/Infrastructure/`：健康检查等共享技术能力
- `app/Providers/`：应用级和认证基础配置
- `routes/`：Web、设置和调度命令入口
- `database/`：迁移、Factory 和 Seeder

Auth 已有完整认证实现；Config、Agent、Customer、Order、Reminder、Settlement、
Audit 和 DataImport 已建立 Phase 2 数据层及导入所需 Application 契约。模块现状
详见[项目状态](../project-status.md)，隔离规则详见[模块边界](module-boundaries.md)。

## 运行与部署边界

仓库提供相互独立的开发 Compose 和单机生产 Compose。生产基线为 Ubuntu Server
24.04 LTS、不可变 app/web 镜像和 Nginx HTTPS；不运行 Vite、不挂载源码，也不在
容器启动时生成密钥或迁移数据库。PostgreSQL、Redis 使用命名卷，私有文件与加密
备份使用受控宿主目录，并由 systemd timer 同步至异机挂载点。具体约束见
[ADR-0003](../adr/0003-single-host-production-baseline.md)和
[生产部署手册](../operations/production-deployment.md)。

## 业务数据与导入

Phase 2 采用 PostgreSQL 关系模型保存机构、代理商、客户、预约、订单、跟进、推广费
快照和月结。DataImport 拥有 staging 与批次元数据，通过数据所有者模块的同步
Application Contract 在单一事务内提交和回滚。私有源文件应用层加密，客户联系方式
和证件使用加密列与不可逆盲索引。

## 尚未形成的架构

完整业务 CRUD、领域事件、月结审核流程、提醒调度、CQRS 和看板预聚合尚未实现。
对应源文档是后续设计输入，不能据此推断当前能力。
