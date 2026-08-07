# ADR-0008：统一 Locale 标识与用户语言偏好

- 状态：Accepted
- 日期：2026-08-07

## 背景

GN-System 已使用 Laravel 的翻译能力，但运行时没有统一的语言白名单、请求级
Locale 解析或用户语言偏好。直接在各页面自行读取语言值会让 Session、Cookie、
用户字段和 HTML `lang` 产生不一致，也可能把任意输入当作语言目录名称。

## 决策

系统第一阶段统一使用 `zh_CN` 和 `ko_KR` 两个受支持标识，默认语言为 `zh_CN`。
共享基础设施中的 `SetLocale` 按以下顺序解析请求语言：已登录用户的
`preferred_locale`、Session、加密 `locale` Cookie、应用默认值。解析结果必须来自
`SupportedLocale` 白名单，并同时设置 Laravel 与 Carbon 的 Locale。

语言切换通过普通 POST 请求完成；匿名用户保存 Session 和加密 Cookie，已登录用户
同时保存 `users.preferred_locale`。匿名用户登录时，非默认的 Session 语言同步到仍为
默认语言的用户记录。第一阶段不把韩文切换入口加入现有业务导航，业务文案迁移在后续
阶段逐模块完成。

## 理由

- 单一 Locale 标识可避免用户字段、Cookie、Carbon 和 HTML 属性之间的格式漂移。
- 白名单解析避免任意输入进入语言目录或产生不可预期的 Locale 行为。
- 普通 POST 加完整重定向可以重新生成根 HTML、Livewire 页面和 Alpine 初始状态。
- 增量增加用户字段，不改变既有用户的默认中文行为，并允许旧版本代码安全回退。

## 后果

- 需要执行新增的向后兼容用户表 migration。
- 语言基础设施已可用，但现有大量业务文案仍未迁移；在后续模块改造完成前不应开放
  韩文入口。
- 队列任务、导出文件、审计和导入错误的 Locale 固定仍属于后续阶段。

## 验证

- `tests/Feature/Localization/LocalePreferenceTest.php` 覆盖默认值、匿名持久化、用户
  持久化、优先级、非法值和登录同步。
- `database/migrations/2026_08_07_000100_add_preferred_locale_to_users_table.php` 验证
  用户偏好字段以默认 `zh_CN` 增量加入。

## 后续实施状态

截至 2026-08-07，业务页面、队列任务、导出、已知审计消息、导入/结算/报表失败和系统提醒已按该决策逐模块完成迁移，韩文入口已经开放。持久化的系统文案使用稳定消息键与命名参数，管理员自定义名称和自由文本不做自动翻译；语言目录的文件/键对称性及 Presentation/Blade 固定中文由自动化测试检查。该状态更新不改变本 ADR 对 Locale 白名单、解析优先级和向后兼容迁移的原始决策。
