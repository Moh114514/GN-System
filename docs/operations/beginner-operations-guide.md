# GN-System 小白运维指南

> 维护要求：系统状态发生变化时，必须同时核对本指南和[完整运维手册](operations-manual.md)。
> 两份文档面向不同读者，但环境、版本、部署状态和已知问题等事实必须保持一致；如果
> 某次变化不影响其中一份，也要在 Pull Request 中说明已经核对。

> 当前基线：2026-08-04
>
> 适用服务器：Ubuntu Server 24.04 LTS，局域网地址 `192.168.0.141`
>
> 当前情况：UAT 已运行；最近已创建 RC 为 `v0.5.0-rc.8`，但当前 `main` 已包含 RC8 之后的月结治理变更；Production 只完成目录初始化，尚未部署

这份文档写给不熟悉 Linux、Docker 和服务器的人。它主要解决四件事：

1. 从局域网内的 Windows 电脑连接服务器；
2. 使用 VS Code 查看服务器文件和终端；
3. 让 Codex 按本项目规则协助排查问题；
4. 完成日常检查，同时避开可能造成数据丢失的命令。

新版本发布、数据库恢复、证书更换等工作，请按照
[GN-System 完整运维手册](operations-manual.md)执行。不要只看本页的零散命令完成
发布或恢复。

## 1. 先认识几个词

| 名称 | 简单理解 |
|---|---|
| 本机/本地电脑 | 你正在使用的 Windows 电脑 |
| 服务器 | 地址为 `192.168.0.141` 的 Ubuntu 主机,是在桌面上运行着大量命令行的那个一体机 |
| SSH | 通过网络打开服务器命令行的安全连接方式 |
| 命令行/终端 | 输入文字命令操作电脑的窗口 |
| UAT | 给公司内部测试和验收新版本的环境 |
| Production | 正式业务环境，简称“生产环境” |
| Docker | 把系统所需程序分开运行的工具 |
| 容器 | Docker 中正在运行的一个服务，例如 Web、数据库或队列 |
| Compose | 统一管理 GN-System 六个容器的工具 |
| 日志 | 程序运行记录，排查报错时通常先看它 |
| `sudo` | 临时用管理员权限执行一条命令 |
| Codex | 可以阅读文件、执行命令、分析代码和日志的编程 Agent |

GN-System 当前有两套服务器环境，不能混用：

| 项目 | UAT | Production |
|---|---|---|
| 用途 | 测试、验收 RC 版本 | 正式业务 |
| 根目录 | `/srv/gn-system` | `/srv/gn-system/production` |
| 仓库目录 | `/srv/gn-system/repository` | `/srv/gn-system/production/repository` |
| 环境文件 | `.env.uat` | `.env.production` |
| Compose 名称 | `gn-system-uat` | `gn-system-production` |
| 访问地址 | `https://gncrm-uat.local:8443` | `https://gncrm.local` |
| 当前状态 | 已运行 | 尚未部署 |

看到 `/srv/gn-system` 时，不要直接把它当成 Git 仓库。UAT 仓库实际在
`/srv/gn-system/repository`。

### 最近版本变化（先看这一段）

| 版本/提交 | 普通话说明 | 运维提醒 |
|---|---|---|
| `v0.5.0-rc.8` / `8ab9498` | 最近一个已经创建的 UAT 候选，包含界面、实时汇率、订单和受控回退 | 只能部署这个明确标签，不能用 `main` 代替 |
| `d405781`、`0971130`、`b2ab309` | 增加往期月结、历史合作资格、周期边界、零订单、汇率失败提示和生成状态 | UAT 要准备历史代理商、零订单、重复点击和报价失败数据 |
| `884f874`、`4aa35d4` | 增加既有月结回填、`unverified` 审计恢复和 `not_applicable` 只读保护 | 部署前必须备份，并确认 `000100` 和 `000200` 两个 migration |
| `main` `2fe5d13` | 已把上述变更合入主分支，但不是已经验收的 RC | 需要创建下一个递增 RC（预计 `v0.5.0-rc.11`）后才能部署 UAT |

服务器实际版本以 `/srv/gn-system/releases/current` 和
`/srv/gn-system/releases/history.tsv` 为准。GitHub 上看到提交，不代表服务器已经运行该提交。

月结详情页会通过接口盒子自动获取每日更新的 KRW/CNY 汇率并预填，审核人可以修改后再提交。刷新失败时页面会明确提示；如果已有旧汇率，系统会标记为“保留旧汇率”，不会把失败显示成成功。
UAT 和 Production 必须分别在环境文件中配置接口 ID/Key；如果服务不可用，页面会提示人工输入，
不要把旧汇率当作实时汇率使用。完整变量和发布要求见[完整运维手册](operations-manual.md)第 3.3 节。

月结中心还可以在“往期月结”区域选择最近已关闭的历史周期生成月结；同一周期已有批次时不会覆盖，
后续仍按普通月结流程审核、生成文档和结清。重复点击时页面会说明批次正在处理、已经完成或部分失败，不会假装重新排队；没有订单的代理商也会生成可审核的零订单月结。

月结失败详情即使遇到代理商没有当月政策等级、资格异常或代理商已删除，也应能正常打开和下载报告；已删除代理商会保留原始 ID，并显示“未知/代理商不存在或已删除”。每次下载都是独立文件，连续点击不会互相覆盖。保存、重试等一次性操作失败会用红色 Toast 提示，仍会阻断页面操作的持续状态才显示顶部提示。

本次修复已核对完整运维手册中的服务器目录、环境文件、数据库和发布流程；没有新增 migration、依赖或服务器命令。部署 UAT 前仍需按完整手册执行门禁，并人工复验失败详情、报告下载和提示行为。

如果是把旧版本升级到包含生成状态字段的版本，先让运维完成数据库备份，再运行 migration。
升级会自动识别已有明细、系统生成快照、结算文档和有效批次；历史导入会显示为“不适用”，无法确认来源的记录会显示为“无法确认”。
“无法确认”的月结不能直接审核。超级管理员要填写核验依据，然后选择归档为历史导入，或创建恢复批次重新生成；系统会记录操作人、前后状态和 IP，普通用户没有这个操作入口。
“不适用”的历史月结只能查看，不能重新生成。迁移后还要抽查待审核、已驳回、已通过、已结清、零订单和历史导入记录，确认明细数量与实际明细一致。
完整步骤见[完整运维手册](operations-manual.md)第 3.3.1 节。

## 2. 连接服务器前需要什么

一台电脑满足下面条件后才能连接：

- 电脑已经连接公司局域网，且能访问 `192.168.0.141`；
- Windows 已安装 OpenSSH 客户端；
- 已取得服务器账号和 SSH 密钥，或管理员允许使用密码登录；
- 防火墙没有阻止 SSH 使用的 22 端口；
- 使用者已获得服务器运维授权。

“局域网内任意电脑”是指任意一台**已授权并配置好凭据**的电脑。不要多人共用或通过
聊天、网盘传递同一份私钥。新电脑最好生成自己的密钥，再由服务器管理员添加它的公钥；
人员或电脑不再使用时，也可以单独撤销。

### 2.1 先检查网络

在 Windows 中右键开始菜单，打开“终端”或 PowerShell，运行：

```powershell
Test-NetConnection 192.168.0.141 -Port 22
```

重点看：

```text
TcpTestSucceeded : True
```

- `True`：网络和 SSH 端口基本可达，可以继续；
- `False`：先检查是否连上同一个局域网、服务器是否开机、IP 是否变化；
- 这一步失败时，不要反复修改密码或 SSH 配置，因为连接还没有到登录阶段。

`ping 192.168.0.141` 可以辅助判断，但有些防火墙会禁止 Ping，所以 Ping 失败不一定
代表服务器不可用，22 端口检查更有参考价值。

### 2.2 检查 Windows 是否有 SSH

```powershell
ssh -V
```

正常情况会显示 OpenSSH 版本。若提示找不到 `ssh`，在 Windows“可选功能”中安装
“OpenSSH 客户端”，安装后重新打开终端。

### 2.3 新电脑怎样准备自己的密钥

如果管理员要求使用密钥登录，可以在新电脑的 PowerShell 中生成一套独立密钥：

```powershell
ssh-keygen -t ed25519 -C "电脑名称-使用者"
```

没有特殊要求时，按 Enter 使用默认保存位置。生成后会有两个文件：

```text
id_ed25519       私钥，只能留在这台电脑
id_ed25519.pub   公钥，可以交给服务器管理员
```

如果默认位置已经存在密钥，看到覆盖询问时选择 `n`，不要覆盖。请改用一个新的文件名，
或先让管理员确认原密钥用途。

查看公钥：

```powershell
Get-Content $env:USERPROFILE\.ssh\id_ed25519.pub
```

只把以 `.pub` 结尾的公钥交给管理员，让管理员将它加入服务器的授权列表。不要发送
`id_ed25519` 私钥，也不要用聊天软件截取包含私钥的终端画面。

## 3. 使用命令行连接

### 3.1 已经配置过 `gn-server`

项目维护电脑通常已经在 SSH 配置中保存了 `gn-server`，直接运行：

```powershell
ssh gn-server
```

首次连接可能询问是否信任主机指纹。先向管理员核对它确实是
`192.168.0.141`，确认无误后输入 `yes`。不要在地址异常或指纹突然变化时直接接受。

### 3.2 新电脑还没有别名

临时连接格式为：

```powershell
ssh <服务器用户名>@192.168.0.141
```

请把 `<服务器用户名>` 替换成管理员分配的真实账号，不要把尖括号原样输入。

想以后使用 `ssh gn-server`，可在下面的文件中添加配置：

```text
C:\Users\<你的Windows用户名>\.ssh\config
```

示例：

```sshconfig
Host gn-server
    HostName 192.168.0.141
    User <服务器用户名>
    IdentityFile C:/Users/<你的Windows用户名>/.ssh/id_ed25519
```

注意：

- `User` 要写服务器账号，不是 Windows 账号；
- `IdentityFile` 要指向这台电脑自己的私钥；
- 如果管理员要求使用其他密钥文件，就按实际路径填写；
- SSH 配置保存后，先在 PowerShell 中测试 `ssh gn-server`，成功后再配置 VS Code。

### 3.3 登录时看不到密码字符

Linux 输入密码或 `sudo` 密码时，屏幕通常不会显示字符，也不会显示星号。这是正常
现象，不是键盘失效。直接输入完整密码并按 Enter。

连接成功后通常会看到类似：

```text
服务器用户名@服务器名:~$
```

这表示后续命令在服务器上运行。结束连接使用：

```bash
exit
```

## 4. 使用 VS Code 连接服务器

VS Code 的 Remote - SSH 扩展可以在本机窗口中浏览服务器文件，并打开服务器终端。
官方说明见 [Remote Development using SSH](https://code.visualstudio.com/docs/remote/ssh)。

### 4.1 第一次准备

1. 在 Windows 安装 Visual Studio Code。
2. 打开左侧“扩展”。
3. 搜索并安装 Microsoft 发布的 `Remote - SSH`。
4. 先在 PowerShell 中确认 `ssh gn-server` 可以正常登录。

### 4.2 从左下角连接

1. 点击 VS Code 左下角的远程连接图标。
2. 选择 `Connect to Host...` 或“连接到主机”。
3. 选择 `gn-server`。
4. 如果没有这个选项，选择 `Add New SSH Host...`，输入
   `ssh <服务器用户名>@192.168.0.141`，再选择 Windows 用户目录下的 SSH
   `config` 文件保存。
5. 第一次被询问服务器系统类型时选择 `Linux`。
6. 按提示输入密码或密钥口令。
7. 等待 VS Code 完成连接。第一次会在服务器安装 VS Code Server，时间可能稍长。

连接成功后，左下角会显示类似：

```text
SSH: gn-server
```

如果左下角没有这行字，先不要把当前终端当成服务器终端。

### 4.3 打开正确的目录

连接 UAT 后选择“文件 → 打开文件夹”，输入：

```text
/srv/gn-system/repository
```

Production 仓库目录是：

```text
/srv/gn-system/production/repository
```

Production 当前尚未部署。没有明确任务时，不要进入 Production 目录进行修改或运行
命令。

打开“终端 → 新建终端”，用下面三条命令确认位置：

```bash
hostname
whoami
pwd
```

### 4.4 VS Code 连接失败

按这个顺序检查：

1. 回到 PowerShell，确认 `ssh gn-server` 是否成功；
2. 运行 `Test-NetConnection 192.168.0.141 -Port 22`；
3. 点击“查看 → 输出”，在下拉菜单选择 `Remote - SSH`；
4. 若没有出现密码输入位置，在 VS Code 设置中启用
   `Remote.SSH: Show Login Terminal` 后重试；
5. 检查 SSH 配置中的 `HostName`、`User` 和 `IdentityFile`；
6. 不要在连接失败时删除服务器文件、重装 Docker或修改 GN-System 配置。

官方故障说明见
[Remote Development Tips and Tricks](https://code.visualstudio.com/docs/remote/troubleshooting)。

## 5. 优先让 Codex 协助排查

服务器出现问题时，优先使用 Codex。它可以按项目文档逐层检查网络、Docker、容器、
健康接口和日志，比把几张零散截图交给普通聊天更容易保留完整证据。

Codex 不是自动获得无限权限的运维机器人。它仍然可能理解错目标环境，因此要让它先
读规则、先只读检查，再决定是否允许修改。

### 5.1 推荐打开方式

在维护电脑上找到：

```text
C:\Users\TFKJ\Desktop\workspace\codex-ssh
```

然后：

1. 右键 `codex-ssh` 文件夹；
2. 选择“通过 Code 打开”或“使用 Visual Studio Code 打开”；
3. 在 VS Code 中打开 Codex 插件；
4. 新建对话时明确要求它先读当前文件夹的 `AGENTS.md`；
5. 让它通过 `ssh gn-server` 连接服务器，不要把服务器项目复制到本机；
6. 让它进入服务器仓库后，再读 `/srv/gn-system/repository/AGENTS.md` 和本指南。

Codex 会在开始任务时读取工作目录中的 `AGENTS.md`。`codex-ssh` 内的这份文件规定了
远程运维边界，服务器仓库中的 `AGENTS.md` 规定了项目、版本、数据和发布规则，两份
都需要遵守。

这是本项目推荐的 AI 运维方式。不要默认在 Remote - SSH 窗口中重新安装并运行 Codex；
那样启动的位置和读到的规则可能不同。人工查看可用 Remote - SSH，Codex 运维则优先
从本机 `codex-ssh` 文件夹启动。

不要直接把 `codex-ssh` 文件夹替换成服务器仓库副本。这个文件夹的用途是保存本机
SSH 运维规则，让 Codex 从本机安全地发起远程检查。

### 5.2 可以直接复制给 Codex 的排障开场

```text
请先完整阅读当前文件夹的 AGENTS.md，然后通过 ssh gn-server 连接服务器。
进入 /srv/gn-system/repository 后，再阅读仓库里的 AGENTS.md 和
docs/operations/beginner-operations-guide.md。

现在 GN-System 出现的问题是：<在这里描述现象、发生时间和操作步骤>。

本轮先只读检查并分析原因，不要修改文件、配置、数据库、容器或服务；
不要输出 .env、密码、令牌、私钥和未脱敏业务数据。
请先确认目标是 UAT 还是 Production，再汇报证据、判断和建议。
```

描述问题时，尽量补充：

- 什么时候开始；
- 哪个页面、哪个账号角色或哪个功能；
- 浏览器显示的完整错误；
- 最近是否部署、断电、重启或改过网络；
- 所有人都受影响，还是只有一台电脑；
- 问题能否重复出现。

### 5.3 需要 Codex 修复时

等只读分析完成后，再发：

```text
根据刚才的只读结论，请先给出最小修复方案，说明：
1. 将修改哪个环境、文件、服务或数据；
2. 是否会中断使用；
3. 操作前要检查什么备份；
4. 如何验证修复成功；
5. 失败后如何回退。

先汇报方案，不要立即执行写操作，等我确认。
```

确认方案时必须看清目标环境。如果 Codex 说不清它正在操作 UAT 还是 Production，
不要批准。

### 5.4 新版本部署的开场

部署不是普通排障。使用：

```text
请先阅读本机 AGENTS.md、服务器仓库 AGENTS.md，以及完整运维手册和发布管理手册。
目标是把 <明确标签，例如 v0.5.0-rc.11> 部署到 <UAT 或 Production>；如果该标签尚未创建，先停止并确认发布流程。

本轮先只读核对 GitHub Actions、标签、镜像、服务器当前版本、目标环境、环境文件权限、
容器状态和可用备份。请汇报部署步骤、影响、验证和回退方案，等我确认后再执行。
```

不要只对 Codex 说“帮我更新一下”。缺少明确标签和环境时，它应当停止并询问。

### 5.5 不要发送给 AI 的内容

- `.env.uat` 或 `.env.production` 完整内容；
- 密码、Token、Cookie、SSH 私钥；
- TLS 私钥；
- 备份压缩包密码；
- 未脱敏的客户、订单、代理商和结算数据；
- 包含以上内容的完整终端截图。

可以提供变量名、脱敏后的错误、有限条数的日志，以及隐去敏感字段的截图。

## 6. 命令行最基础的操作

### 6.1 常用命令

| 命令 | 作用 | 是否通常安全 |
|---|---|---|
| `pwd` | 显示当前目录 | 只读 |
| `ls` | 显示当前目录内容 | 只读 |
| `ls -lah` | 显示更完整的文件信息 | 只读 |
| `cd /某个目录` | 进入目录 | 不修改文件 |
| `cd ..` | 返回上一级目录 | 不修改文件 |
| `cd ~` | 返回当前用户主目录 | 不修改文件 |
| `clear` | 清空当前终端显示 | 不影响服务 |
| `history` | 查看当前账号的历史命令 | 只读，但输出可能含敏感信息 |
| `cat 文件名` | 一次显示整个文本文件 | 只读，不适合大文件或敏感文件 |
| `less 文件名` | 分页查看文件，按 `q` 退出 | 只读 |
| `head -n 20 文件名` | 查看开头 20 行 | 只读 |
| `tail -n 100 文件名` | 查看最后 100 行 | 只读 |
| `exit` | 退出 SSH | 不关闭服务器 |

Linux 路径区分大小写。`Data` 和 `data` 是两个不同名称。

按 Tab 可以补全命令或路径，建议多用它减少输错。方向键上可以找回上一条命令，但
执行前要重新读一遍，不要在错误目录重复历史命令。

### 6.2 “没有输出”通常是什么意思

很多 Linux 命令成功时不会输出，例如：

```bash
cd /srv/gn-system/repository
```

只要命令结束并重新出现 `$` 提示符，通常表示它已经执行完毕。可用下面命令查看上一条
命令的退出码：

```bash
echo $?
```

- `0` 通常表示成功；
- 非 `0` 通常表示失败；
- 退出码只能说明命令是否报告成功，不能代替业务检查。

如果迟迟没有重新出现提示符，命令可能仍在运行或等待输入。

### 6.3 `Ctrl+C` 会做什么

`Ctrl+C` 通常是结束当前前台命令或停止继续显示日志，不是关闭服务器。

例如执行：

```bash
sudo docker compose --env-file .env.uat \
  -f compose.production.yaml logs --follow --tail 200
```

日志会持续显示。按 `Ctrl+C` 只是退出日志查看，不会停止容器。

有些命令正好在等待网络或磁盘结果，第一次按 `Ctrl+C` 后才返回提示符，看起来像
“突然动了”。这是正常的中断表现。不要连续乱按其他快捷键。

### 6.4 `sudo` 是什么

`sudo` 表示用系统管理员权限执行一条命令。它不是“让命令更容易成功”的通用开关。

看到下面这类命令时应格外谨慎：

```bash
sudo ...
```

如果不知道命令会修改什么，先不要运行。输入 `sudo` 密码时屏幕不显示字符是正常的。

### 6.5 使用 Nano 编辑器

只有在手册明确要求修改环境文件，且已经核对目标环境时才使用：

```bash
sudo nano <明确文件>
```

常用按键：

- 方向键：移动；
- `Ctrl+O`：保存，再按 Enter 确认文件名；
- `Ctrl+X`：退出；
- 退出时出现保存询问：看清改动后再选择；
- `Ctrl+W`：搜索。

不要用 Nano 直接修改服务器仓库中的 PHP、Blade、JavaScript、Compose 或部署脚本。
代码修改必须在开发分支完成并通过发布流程。

## 7. 每次登录后的安全检查

当前 UAT 已运行，日常检查以 UAT 为主。

### 7.1 确认自己在哪里

```bash
hostname
whoami
pwd
date
```

然后进入 UAT 仓库：

```bash
cd /srv/gn-system/repository
```

检查 Git 和当前版本：

```bash
git status --short --branch
git describe --tags --exact-match
cat /srv/gn-system/releases/current
```

服务器发布仓库通常停在明确标签的 detached HEAD，这是正常的。不要因为看到
`detached HEAD` 就随手切回 `main` 或运行 `git pull`。

### 7.2 查看六个服务

```bash
sudo docker compose \
  --env-file .env.uat \
  -f compose.production.yaml \
  ps
```

应看到：

- `web`
- `app`
- `queue`
- `scheduler`
- `postgres`
- `redis`

六个服务都应处于运行状态；`web`、`app`、`postgres`、`redis` 的健康状态应正常。

### 7.3 查看三个健康接口

下面的写法会明确显示 HTTP 状态码：

```bash
curl --silent --show-error --output /dev/null \
  --write-out '/up: HTTP %{http_code}\n' \
  https://gncrm-uat.local:8443/up

curl --silent --show-error --output /dev/null \
  --write-out '/health: HTTP %{http_code}\n' \
  https://gncrm-uat.local:8443/health

curl --silent --show-error --output /dev/null \
  --write-out '/health/operations: HTTP %{http_code}\n' \
  https://gncrm-uat.local:8443/health/operations
```

正常应为 `HTTP 200`：

| 地址 | 检查内容 |
|---|---|
| `/up` | Laravel 和 PHP 是否能响应 |
| `/health` | PostgreSQL 和 Redis 是否可用 |
| `/health/operations` | Queue 和 Scheduler 是否有最近心跳 |

不要用 `curl -k` 作为“系统正常”的最终证据。它会跳过证书校验，只能临时帮助判断
问题是否来自证书信任。

### 7.4 查看磁盘、内存和运行时间

```bash
df -h
free -h
uptime
sudo docker stats --no-stream
```

磁盘满了也不要直接运行 Docker 清理命令。先让 Codex 或熟悉服务器的人分析哪些内容
可以安全删除。

### 7.5 查看有限条数的日志

查看 UAT 最近 200 行日志：

```bash
sudo docker compose \
  --env-file .env.uat \
  -f compose.production.yaml \
  logs --tail 200 app web
```

队列和定时任务：

```bash
sudo docker compose \
  --env-file .env.uat \
  -f compose.production.yaml \
  logs --tail 200 queue scheduler
```

持续查看时加 `--follow`，结束按 `Ctrl+C`。不要直接导出没有时间和条数限制的全部日志，
其中可能包含业务信息。

## 8. 常见现象先怎么看

| 现象 | 先做什么 | 先不要做什么 |
|---|---|---|
| 所有电脑都打不开网页 | 检查 22、8080、8443 端口，查看 Compose 状态 | 不要立即重装或删除容器 |
| 只有一台电脑打不开 | 检查这台电脑网络、hosts 和证书信任 | 不要重启整台服务器 |
| 页面显示 500 | 保存时间和页面，查看 `app`、`web` 最近日志 | 不要改数据库或源码 |
| 页面显示 502/504 | 查看 `web`、`app` 状态和日志 | 不要先恢复数据库 |
| 登录或 TOTP 突然失效 | 检查是否改过 `APP_KEY`、时间和环境 | 不要生成新的 `APP_KEY` 覆盖 |
| `/health` 失败 | 查看 `app`、`postgres`、`redis` | 不要暴露数据库端口 |
| `/health/operations` 失败 | 查看 `queue`、`scheduler`，等待心跳刷新 | 不要只重启 `web` |
| 收到备份失败邮件 | 查看备份列表和相关日志 | 不要只删除邮件 |
| 浏览器证书报警 | 检查访问域名、证书有效期和客户端信任 | 不要长期点击忽略 |
| 磁盘空间不足 | 查清目录和 Docker 占用 | 不要运行 `prune -a --volumes` |

把结果交给 Codex 时，提供命令、有限输出、时间和现象，不要只说“服务器坏了”。

## 9. 哪些操作会影响正在使用的人

下面不是只读命令。只有明确判断需要，并确认目标是 UAT 后才执行。

### 9.1 重启 Queue 和 Scheduler

```bash
cd /srv/gn-system/repository
sudo docker compose --env-file .env.uat \
  -f compose.production.yaml restart queue scheduler
```

正在处理的后台任务可能中断后重试。执行后要检查：

```bash
sudo docker compose --env-file .env.uat \
  -f compose.production.yaml ps queue scheduler
curl --fail --show-error \
  https://gncrm-uat.local:8443/health/operations
```

### 9.2 重启应用和 Web

```bash
cd /srv/gn-system/repository
sudo docker compose --env-file .env.uat \
  -f compose.production.yaml restart app web
```

网页会短暂不可用。执行后要检查六个服务和三个健康接口。

### 9.3 重启或关闭服务器

```bash
sudo reboot
```

会重启整台服务器，SSH 会断开，UAT 会暂时不可用。服务器恢复后必须重新登录，检查
六个服务、三个健康接口、Queue、Scheduler 和备份。

安全关机：

```bash
sudo shutdown -h now
```

关机后不能通过 SSH 远程开机，除非另有远程电源管理。不要把“网页打不开”直接等同于
“应该重启服务器”。

## 10. 明确禁止照抄的危险命令

不了解完整影响时，禁止运行：

```bash
docker compose down -v
docker system prune -a --volumes
php artisan migrate:fresh
php artisan db:wipe
rm -rf <目录>
git reset --hard
git clean -fd
```

它们可能删除数据库卷、缓存、镜像、文件或未提交修改。命令前面加 `sudo` 不会让它更
安全。

还要遵守：

- 不在服务器直接修改 `app/`、`resources/`、`routes/` 等源码；
- 不在服务器运行 `composer install`、`npm install` 或 `docker build`；
- 不通过 `git pull` 直接更新系统；
- 不部署 `latest`、分支名或未发布提交；
- 不移动、删除或复用已经推送的版本标签；
- 不把 UAT 数据、账号、密钥或证书复制给 Production；
- 不随意修改已经运行环境的 `APP_KEY`；
- 不把 `.env`、私钥、密码、令牌或备份发送到聊天中；
- 不在没有确认备份和恢复方法时执行数据库修改或版本回退。

## 11. 新版本不是“拉一下代码”

GN-System 的服务器版本来自已经发布的标签和 GHCR 镜像，不是在服务器执行
`git pull` 后现场构建。

简化流程是：

```text
开发分支
  → PR 合入 develop
  → 发布 PR 合入 main
  → 创建 RC 标签
  → GitHub Actions 构建镜像
  → UAT 部署和验收
  → 创建同一提交的正式标签
  → Production 部署
```

UAT 只部署 `vX.Y.Z-rc.N`，Production 只部署 `vX.Y.Z`。版本部署前必须确认标签、
镜像、环境、备份和回退方式。服务器访问 GHCR 不稳定时，不要反复执行标准部署脚本；
应阅读[局域网离线镜像部署](offline-deployment.md)，由办公电脑下载明确版本镜像，
通过局域网传到服务器并使用 `--pull never` 完成部署。

实际部署时阅读：

- [GN-System 完整运维手册](operations-manual.md)
- [UAT 测试版本与正式发布流程](release-management.md)
- [局域网离线镜像部署](offline-deployment.md)
- [局域网生产部署与恢复](production-deployment.md)

## 12. Windows 浏览器访问 UAT

SSH 能连接不代表浏览器一定认识 `gncrm-uat.local`。如果公司还没有内网 DNS，
Windows hosts 文件需要包含：

```text
192.168.0.141 gncrm-uat.local
```

hosts 文件位置：

```text
C:\Windows\System32\drivers\etc\hosts
```

修改时需要管理员权限。保存后可以在 PowerShell 检查：

```powershell
ping gncrm-uat.local
Test-NetConnection gncrm-uat.local -Port 8443
```

UAT 地址：

```text
https://gncrm-uat.local:8443
```

若域名能访问但浏览器仍警告证书，需要把签发该证书的根证书加入 Windows 的受信任根
证书。客户端只安装根证书或需要公开的证书链，绝不能分发服务器私钥。

Production 开放后使用 `https://gncrm.local`，端口为标准的 443。当前不要把 UAT
误写成 `https://gncrm.local`。

## 13. 建议的日常检查频率

### 每天或有人反馈异常时

- 确认六个服务正常；
- 确认三个健康接口返回 200；
- 看最近备份时间是否合理；
- 看磁盘是否接近满载；
- 看 `app`、`queue`、`scheduler` 是否连续报错。

### 每周

- 检查 Docker 和主机磁盘占用趋势；
- 检查是否有长期失败的后台任务或备份；
- 检查 Ubuntu 和浏览器访问是否正常；
- 检查是否有人在服务器仓库留下受跟踪修改；
- 记录发现的问题和处理结果。

### 每月

- 检查证书到期时间；
- 在独立空环境进行一次备份恢复演练；
- 检查 Production 异机备份。Production 尚未部署时，此项标记为未启用，不能写成
  “已经正常”；
- 清理已离职人员或停用电脑的 SSH 公钥和账号。

## 14. 最短日常检查清单

只想快速确认 UAT 是否正常时：

```bash
ssh gn-server
cd /srv/gn-system/repository

sudo docker compose --env-file .env.uat \
  -f compose.production.yaml ps

curl --silent --show-error --output /dev/null \
  --write-out '/up: HTTP %{http_code}\n' \
  https://gncrm-uat.local:8443/up
curl --silent --show-error --output /dev/null \
  --write-out '/health: HTTP %{http_code}\n' \
  https://gncrm-uat.local:8443/health
curl --silent --show-error --output /dev/null \
  --write-out '/health/operations: HTTP %{http_code}\n' \
  https://gncrm-uat.local:8443/health/operations

df -h
free -h
exit
```

如果有一项异常，先保存输出，再用第 5 节的模板交给 Codex 做只读分析。不要边猜边改。

## 15. 一句话判断是否该停手

出现下面任意一种情况，就先停止执行并让 Codex 或熟悉项目的人核对：

- 不确定当前是 UAT 还是 Production；
- 命令中出现 `-v`、`--volumes`、`fresh`、`wipe`、`rm`、`reset --hard`；
- 准备修改数据库、`.env`、证书、网络或备份；
- 准备部署、回退或恢复；
- 发现 Git 仓库存在未提交修改；
- 看到了密码、令牌或私钥，准备把它贴到聊天；
- Codex 无法说明修改目标、影响、验证和回退方法；
- 实际服务器状态和文档不一致。

停下来核对不会让问题变严重；在环境不明时继续尝试，才容易把小问题变成数据或服务
事故。
## PR-C UAT reset and configuration reload

These commands must be run on the UAT host from the repository `/srv/gn-system/repository`, not from Production. The UAT root is `/srv/gn-system`:

```bash
cd /srv/gn-system/repository
./deploy/reset-uat.sh --business-data
./deploy/reload-config.sh uat
```

Business-data reset keeps administrator accounts and base configuration. It asks for `RESET gn_system_uat`, makes a backup, clears approved UAT business records and private business files, flushes UAT Redis, restarts services, and checks all three health URLs with the UAT certificate and a bounded 180-second retry for worker heartbeats. For a complete first-time UAT initialization use `./deploy/reset-uat.sh --full` only after confirming the target and accepting that the UAT database will be rebuilt. It also requires the same exact phrase and asks you to create a new administrator.

The reset writes phase audit records after the database cleanup: database cleanup completed, private files cleanup completed, and reset completed. If a later phase fails, it records reset failed and the phase when the audit backend is available.

Use the administrator commands to disable or enable accounts and rotate passwords. Always provide both a reason and an operator or ticket identifier. Passwords are entered at a hidden prompt; do not put them in shell history. The last active super administrator cannot be disabled, and there is no delete-admin command.

Never run these operations in `/srv/gn-system/production`, against `gn_system`, or with `docker compose down -v`. Do not copy secrets into chat or expect the reload script to print them.
