<div @if (in_array($this->selectedBatch?->status, [\App\Modules\DataImport\Domain\ImportBatchStatus::Uploaded, \App\Modules\DataImport\Domain\ImportBatchStatus::Parsing], true)) wire:poll.3s @endif>
    <x-page-back :href="route('configuration.index')" label="返回配置中心" class="mb-4" />

    <section class="crm-section-header">
        <div>
            <p class="crm-eyebrow">配置中心 · 批量维护</p>
            <h2>基础配置导入</h2>
            <p>单个 XLSX 分八个工作表导入。上传只进行预览和校验，必须由管理员再次确认后才会写入。</p>
        </div>
        <flux:button wire:click="downloadExample" variant="ghost" icon="arrow-down-tray">下载填写示例</flux:button>
    </section>

    @if (session('status'))
        <div class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>
    @endif

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
                        <span class="block font-mono text-xs">{{ $batch->id }}</span>
                        <span class="mt-1 block text-sm">状态：{{ $batch->status->value }} · {{ $batch->total_rows }} 行</span>
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
                        @if (in_array($batch->status, [\App\Modules\DataImport\Domain\ImportBatchStatus::Failed, \App\Modules\DataImport\Domain\ImportBatchStatus::NeedsReview], true))
                            <flux:button wire:click="reparse" variant="ghost">重新解析</flux:button>
                        @endif
                    </div>

                    @if ($batch->failure_reason)
                        <div class="mt-4 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">{{ $batch->failure_reason }}</div>
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
                    <h3 class="font-semibold">前 100 行校验结果</h3>
                    <div class="crm-table-wrap mt-4">
                        <table class="crm-table">
                            <thead><tr><th>工作表/行</th><th>结果</th><th>规范化预览</th><th>错误</th></tr></thead>
                            <tbody>
                                @forelse ($batch->rows as $row)
                                    <tr>
                                        <td>{{ $row->sheet_name }} #{{ $row->source_row }}</td>
                                        <td>{{ $row->status->value }}</td>
                                        <td class="max-w-xl break-all text-xs">{{ json_encode($row->normalized_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</td>
                                        <td class="text-sm text-red-600">{{ implode('；', $row->errors ?? []) }}</td>
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
                        <p class="mt-1 text-sm text-amber-800">校验和事务预演已通过。确认后将一次性写入全部有效行；任一写入失败会回滚整个事务。</p>
                        <div class="mt-4">
                            <flux:checkbox wire:model="confirmImport" label="我已核对预览，并确认按上述顺序写入全部基础配置" />
                            @error('confirmImport') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <flux:button wire:click="commit" variant="primary" class="mt-4" wire:confirm="确认写入本批次全部基础配置吗？">确认写入</flux:button>
                    </section>
                @endif
            @else
                <section class="rounded-2xl border border-dashed border-zinc-300 p-10 text-center text-zinc-500">请选择或上传一个批次查看预览。</section>
            @endif
        </div>
    </section>
</div>
