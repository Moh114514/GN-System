# GN-System 项目长期约定

## 前端技术栈
- Laravel 模块化单体；前端 = Livewire 4 + Flux UI 2（免费版）+ Tailwind CSS 4 + Alpine.js + ECharts 6 + Vite 8。**非 SPA**。
- Blade 视图在 `resources/views/`；Livewire 组件类在 `app/Modules/*/Presentation/Livewire/`（Presentation 层）。
- `crm-eyebrow` / `crm-section-header` 在 app.css 中**无样式定义**（仅语义钩子）。
- Tailwind 配置内置于 `resources/css/app.css` 的 `@theme`（无 tailwind.config.js）。新增工具类需跑 `npm run build` 重新扫描。

## 状态英文枚举 → 中文标签（项目惯例）
- **展示层约束下不改 Domain 枚举**：优先用 blade 内联 `@php` 映射数组（settlement-center / reminder-center / import-manager 均如此）。
- 部分 Domain 枚举已有 `label()`（如 ImportProfile），视图直接 `$x->profile->label()` 即可。
- 参考：import-manager 的 `$batchStatusLabels` / `$rowStatusLabels` / `$fileStatusLabels` / `$batchStatusColors`。

## UI 设计模板（2026-08-03 用户指定，后续页面照此）
以历史数据导入页为模板：
- 标题区：大标题 + 一句业务说明 + 步骤流程 chip（`rounded-full` 柔和 teal chip，无数字，"步骤A → 步骤B → 步骤C"）。
- 状态/就绪横幅：图标（check-circle / exclamation-triangle）+ 平实文案。
- 文件上传：虚线拖拽上传区（cloud-arrow-up + "选择文件 或 拖拽文件到此处"），隐形 input 覆盖保留原功能，附已选文件列表。
- 低频功能：用"高级 XX 设置（可选）"卡片弱化，副标题说明价值。
- 列表记录：主行 + 状态·数量，状态着色（failed=红、待处理=琥珀、完成=绿、通过=teal）。
- 文案：面向业务人员，避免 Phase/Batch/原子/事务/校验/映射 等技术词；按钮用行为语言。

## 质量门禁（提交前必跑）
- `docker compose exec app composer ci:check`（含 docs:check、Pint、PHPStan level 6、全量测试；测试入口 `scripts/run-tests.php`，可 `--filter=xxx`）
- `docker compose exec vite npm run build`
- 改文案会连带 Feature 测试的 `assertSee` 断言，必须同步更新，不能删测试。
