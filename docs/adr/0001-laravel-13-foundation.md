# ADR-0001：采用 Laravel 13 与官方 Livewire Starter Kit

- 状态：已接受
- 日期：2026-07-24

## 背景

原技术路线以 Laravel 11、Livewire 3 和旧式认证脚手架为基线。Phase 1 实施时，
Laravel 11 已于 2026-03-12 结束安全更新，不适合作为新系统的长期基础。
项目需要内部账号认证、TOTP 双因素认证、深色模式和可持续维护的组件体系。

## 决策

采用 PHP 8.3、Laravel 13、官方 Livewire Starter Kit、Livewire 4、Flux UI 2
免费版和 Tailwind CSS 4。认证由 Fortify 提供，不引入 Breeze 或 Flux Pro。

本地和 CI 均采用 Docker-first：运行时依赖不在 Windows 主机安装。应用保持
模块化单体，模块之间只通过 Application 层契约或领域事件协作。

## 理由

- Laravel 13 的安全支持覆盖到 2028 年第一季度，显著延长基础版本寿命。
- 官方 Livewire Starter Kit 与当前 Livewire、Flux UI 和 Tailwind 版本同步。
- Fortify 原生支持登录限流、密码确认、TOTP 及恢复码，减少自建安全流程。
- 免费 Flux UI 已足够覆盖 Phase 1，不需要购买或提交 Flux Pro 凭据。

## 后果

- 原技术路线中的 Laravel 11 / Livewire 3 版本说明升级为 13 / 4。
- 后续业务模块应遵守 Laravel 13、Livewire 4 API 及模块边界测试。
- 框架主版本升级仍需独立 ADR、迁移验证和回归测试。
- 生产平台尚未确定，因此本 ADR 不决定云厂商、HTTPS、对象存储或部署方式。

## 参考

- <https://laravel.com/docs/12.x/releases>
- <https://laravel.com/docs/13.x/starter-kits>
