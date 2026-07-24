# GN-System CRM

GN-System 是面向医美/医疗代理业务的内部客户管理系统。项目计划将现有
Excel 中分散的客户、代理商、订单和结算数据迁移到统一的 Web 系统，并提供
客户跟进、推广费核算、月结、提醒、多维查询、经营看板和系统配置能力。

当前仓库处于 **Phase 0：项目准备**。本阶段只建立文档、协作规范和本地开发
环境，不包含 Laravel 应用、数据库或生产部署。

## 技术基线

- 架构：Laravel 模块化单体
- 后端：PHP 8.3、Laravel 11
- 前端：Livewire 3、FluxUI、Alpine.js
- 数据：PostgreSQL 16（Phase 1 默认）、Redis 7
- 本地环境：Docker Desktop + Docker Compose
- 质量门禁：Pest、PHPStan、Laravel Pint

PHP、Composer、Node.js、PostgreSQL 和 Redis 统一在容器中运行，不要求在
Windows 主机单独安装。

## 目录

```text
GN-System/
├── docs/source/       # 需求、架构和技术路线原始文档
├── .vscode/           # 推荐扩展和共享编辑器设置
├── .editorconfig      # 跨编辑器格式约定
├── .env.example       # 非敏感环境变量模板
└── README.md
```

Laravel 应用与 Docker Compose 配置将在 Phase 1 加入。

## 本地环境准备

1. 安装 Git、Docker Desktop，并启用 Docker 的 WSL2/Linux 容器后端。
2. 克隆仓库并进入项目目录：

   ```powershell
   git clone https://github.com/Moh114514/GN-System.git
   Set-Location GN-System
   ```

3. 验证基础工具：

   ```powershell
   git --version
   docker version
   docker compose version
   docker run --rm hello-world
   ```

4. 复制本地环境模板：

   ```powershell
   Copy-Item .env.example .env
   ```

`.env` 只用于本地，禁止提交。真实密码、令牌、证书和生产配置不得写入仓库。

## 分支与提交规范

- `main`：稳定基线，只接收已经验证的变更。
- `develop`：日常集成分支。
- `feature/<topic>`：从 `develop` 创建的功能分支，完成后合并回 `develop`。
- 提交信息使用简短的祈使句，并保持一次提交只表达一个完整变更。

典型流程：

```powershell
git switch develop
git pull --ff-only
git switch -c feature/<topic>
```

禁止把 `.env`、密钥、数据库数据、依赖目录或构建产物提交到 Git。

## 项目文档

- [CRM 需求文档 v1.9](docs/source/CRM-需求文档-v1.9.md)
- [CRM 系统架构设计](docs/source/CRM-系统架构设计.md)
- [CRM 开发技术路线](docs/source/CRM-开发技术路线文档.md)
- [CRM 系统架构图](docs/source/CRM-系统架构图.html)

## 下一阶段

Phase 1 将创建 Laravel 11 模块化单体骨架，以及 Nginx、PHP-FPM、
PostgreSQL、Redis、队列、调度和 CI/CD 配置。完成标志是
`docker compose up` 可启动登录页，且自动化质量检查通过。
