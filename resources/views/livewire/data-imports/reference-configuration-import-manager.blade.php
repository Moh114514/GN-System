<div @if (in_array($this->selectedBatch?->status, [\App\Modules\DataImport\Domain\ImportBatchStatus::Uploaded, \App\Modules\DataImport\Domain\ImportBatchStatus::Parsing], true)) wire:poll.3s @endif>
    <x-page-back :href="route('configuration.data-maintenance')" label="返回数据导入与迁移" class="mb-4" />

    @php
        $batchStatusLabels = [
            'uploaded' => '已上传',
            'parsing' => '检查中',
            'needs_review' => '待处理',
            'validated' => '检查通过',
            'committing' => '导入中',
            'completed' => '已完成',
            'failed' => '失败',
            'rolled_back' => '已撤销',
            'expired' => '已过期',
        ];
    @endphp
    <section class="crm-section-header">
        <div>
            <p class="text-xs font-medium text-zinc-400">配置中心 · 批量维护</p>
            <h2 class="mt-1 text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-50">基础配置导入</h2>
            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">用一个包含八个工作表的 XLSX 工作簿批量维护基础配置。上传后先预览和检查，由管理员确认后才会正式写入。</p>
            <p class="mt-4 flex flex-wrap items-center gap-x-3 gap-y-2 text-sm">
                <span class="rounded-full border border-zinc-200 bg-white px-4 py-1.5 font-medium text-zinc-700 shadow-sm dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">上传工作簿</span>
                <span class="text-zinc-300 dark:text-zinc-600" aria-hidden="true">→</span>
                <span class="rounded-full border border-zinc-200 bg-white px-4 py-1.5 font-medium text-zinc-700 shadow-sm dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">检查预览</span>
                <span class="text-zinc-300 dark:text-zinc-600" aria-hidden="true">→</span>
                <span class="rounded-full border border-zinc-200 bg-white px-4 py-1.5 font-medium text-zinc-700 shadow-sm dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">确认写入</span>
            </p>
        </div>
        <flux:button wire:click="downloadExample" variant="ghost" icon="arrow-down-tray">下载填写示例</flux:button>
    </section>

    <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <h3 class="text-lg font-semibold">上传工作簿</h3>
        <p class="mt-1 text-sm text-zinc-600">处理顺序：基础字典 → 政策等级 → 费率 → 代理商 → 等级分配。每次仅接受一个不超过 20MB 的 XLSX。</p>
        <form wire:submit="stageWorkbook" class="mt-5 space-y-4">
            <flux:input wire:model="workbook" type="file" accept=".xlsx" label="基础配置工作簿" />
            @error('workbook') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
            <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="workbook,stageWorkbook">
                上传并生成预览
            </flux:button>
        </form>
    </section>

    <section class="mt-6 grid gap-6 xl:grid-cols-[20rem_1fr]">
        <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <h3 class="font-semibold">最近批次</h3>
            <div class="mt-4 space-y-2">
                @forelse ($this->batches as $batch)
                    <button type="button" wire:click="selectBatch('{{ $batch->id }}')" class="w-full rounded-xl border p-3 text-left {{ $selectedBatchId === $batch->id ? 'border-teal-500 bg-teal-50' : 'border-zinc-200' }}">
                        <span class="flex items-center justify-between gap-2">
                            <strong>{{ $batchStatusLabels[$batch->status->value] ?? $batch->status->value }}</strong>
                            <span class="text-xs text-zinc-400">{{ $batch->created_at->format('Y-m-d H:i') }}</span>
                        </span>
                        <span class="mt-1 block text-sm text-zinc-500">共 {{ $batch->total_rows }} 行</span>
                        <span class="mt-0.5 block truncate text-xs text-zinc-400">编号 {{ $batch->id }}</span>
                    </button>
                @empty
                    <p class="text-sm text-zinc-500">尚无基础配置导入批次。</p>
                @endforelse
            </div>
        </div>

        <div class="space-y-5">
            @if ($this->selectedBatch)
                @php($batch = $this->selectedBatch)
                <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h3 class="font-semibold">批次预览</h3>
                            <p class="mt-1 text-sm text-zinc-600">有效 {{ $batch->valid_rows }} · 错误 {{ $batch->error_rows }} · 总计 {{ $batch->total_rows }}</p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            @if ($batch->error_rows > 0 || $batch->failure_reason)
                                <flux:button wire:click="downloadErrors" variant="ghost" icon="arrow-down-tray">
                                    下载错误报告
                                </flux:button>
                            @endif
                            @if (in_array($batch->status, [\App\Modules\DataImport\Domain\ImportBatchStatus::Failed, \App\Modules\DataImport\Domain\ImportBatchStatus::NeedsReview], true))
                                <flux:button wire:click="reparse" variant="ghost">重新检查</flux:button>
                            @endif
                        </div>
                    </div>

                    @if ($batch->failure_reason)
                        <div class="mt-4 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                            <p class="font-semibold">工作簿处理失败</p>
                            <p class="mt-1">{{ $batch->failure_reason }}</p>
                            <p class="mt-2 text-red-600">请下载错误报告查看完整信息，修正工作簿后重新上传。</p>
                        </div>
                    @elseif ($batch->error_rows > 0)
                        <div class="mt-4 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                            <p class="font-semibold">发现 {{ $batch->error_rows }} 行错误，当前批次不能写入。</p>
                            <p class="mt-1">请根据下方工作表、源行号和错误详情修改 XLSX；也可以下载错误报告集中处理。</p>
                        </div>
                    @endif

                    @if (! empty(($batch->summary ?? [])['stages']))
                        <div class="mt-5 overflow-x-auto">
                            <table class="min-w-full text-left text-sm">
                                <thead class="border-b border-zinc-200 text-zinc-500"><tr><th class="px-3 py-2">阶段</th><th class="px-3 py-2">状态</th><th class="px-3 py-2">指标</th></tr></thead>
                                <tbody class="divide-y divide-zinc-100">
                                    @foreach (($batch->summary['stages'] ?? []) as $stage => $state)
                                        <tr><td class="px-3 py-2 font-medium">{{ match ($stage) { 'file_detection' => '文件识别', 'field_validation' => '字段校验', 'normalization' => '数据标准化', 'relation_validation' => '关联校验', 'summary_validation' => '汇总校验', 'dry_run' => '事务预演', 'commit' => '正式写入', default => $stage } }}</td><td class="px-3 py-2">{{ match ($state['status'] ?? 'pending') { 'pending' => '待处理', 'running' => '进行中', 'passed' => '通过', 'failed' => '失败', 'not_started' => '未开始', default => $state['status'] ?? 'pending' } }}</td><td class="px-3 py-2 text-zinc-500">{{ collect($state)->except('status')->map(fn ($value, $key) => (match ($key) { 'issue_count' => '问题数', 'passed_rows' => '通过行数', 'warning_rows' => '警告行数', 'error_rows' => '错误行数', default => $key }).': '.$value)->implode(' / ') ?: '-' }}</td></tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif

                    @foreach ($batch->files as $file)
                        @if ($file->preflight)
                            <div class="mt-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                                @foreach ($file->preflight['sheets'] ?? [] as $sheet)
                                    <div class="rounded-lg bg-zinc-50 p-3 text-sm dark:bg-zinc-800">
                                        <span class="font-semibold">{{ $sheet['name'] }}</span>
                                        <span class="block text-xs text-zinc-500">表头第 {{ $sheet['header_row'] }} 行</span>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    @endforeach
                </section>

                <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <h3 class="font-semibold">前 100 行检查结果</h3>
                    <div class="crm-table-wrap mt-4">
                        <table class="crm-table">
                            <thead><tr><th>工作表/源行号</th><th>结果</th><th>数据预览</th><th>错误详情</th></tr></thead>
                            <tbody>
                                @forelse ($batch->rows as $row)
                                    <tr>
                                        <td>{{ $row->sheet_name }} #{{ $row->source_row }}</td>
                                        <td>
                                            @if ($row->status === \App\Modules\DataImport\Domain\ImportRowStatus::Valid)
                                                <span class="text-emerald-700">通过</span>
                                            @else
                                                <span class="font-medium text-red-700">错误</span>
                                            @endif
                                        </td>
                                        <td class="max-w-xl break-all text-xs">
                                            {{ json_encode(
                                                $row->status === \App\Modules\DataImport\Domain\ImportRowStatus::Valid
                                                    ? $row->normalized_data
                                                    : $row->raw_payload_encrypted,
                                                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                                            ) }}
                                        </td>
                                        <td class="text-sm text-red-600">
                                            @foreach ($row->errors ?? [] as $error)
                                                <span class="block">{{ $error }}</span>
                                            @endforeach
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="py-8 text-center text-zinc-500">暂无可预览数据。</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </section>

                @if ($batch->status === \App\Modules\DataImport\Domain\ImportBatchStatus::Validated)
                    <section class="rounded-2xl border border-amber-300 bg-amber-50 p-5">
                        <h3 class="font-semibold text-amber-900">管理员最终确认</h3>
                        <p class="mt-1 text-sm text-amber-800">检查已通过。确认后将一次性写入全部有效数据；如果中途出错，已写入的内容会自动撤销，不会留下不完整的数据。</p>
                        <div class="mt-4">
                            <flux:checkbox wire:model="confirmImport" label="我已核对预览，并确认按上述顺序写入全部基础配置" />
                            @error('confirmImport') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <flux:button wire:click="commitBatch" variant="primary" class="mt-4" wire:confirm="确认写入本批次全部基础配置吗？">确认写入</flux:button>
                    </section>
                @endif
            @else
                <section class="rounded-2xl border border-dashed border-zinc-300 p-10 text-center text-zinc-500">请选择或上传一个批次查看预览。</section>
            @endif
        </div>
    </section>
</div>
