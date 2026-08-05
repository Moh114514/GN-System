<div class="crm-content">
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
        $rowStatusLabels = [
            'valid' => '通过',
            'warning' => '警告',
            'error' => '错误',
            'ignored' => '已忽略',
            'duplicate_candidate' => '疑似重复',
            'resolved' => '已处理',
        ];
        $fileStatusLabels = [
            'uploaded' => '已上传',
            'parsing' => '检查中',
            'parsed' => '已检查',
            'failed' => '失败',
        ];
        $batchStatusColors = [
            'failed' => 'text-red-600',
            'needs_review' => 'text-amber-600',
            'expired' => 'text-amber-600',
            'completed' => 'text-emerald-600',
            'validated' => 'text-teal-600',
        ];
    @endphp
    <section class="crm-section-header">
        <div>
            <h2 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-50">历史数据导入</h2>
            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">上传历史业务数据文件，系统会在正式导入前自动检查格式、重复数据和异常记录。</p>
            <p class="mt-4 flex flex-wrap items-center gap-x-3 gap-y-2 text-sm">
                <span class="rounded-full border border-zinc-200 bg-white px-4 py-1.5 font-medium text-zinc-700 shadow-sm dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">上传文件</span>
                <span class="text-zinc-300 dark:text-zinc-600" aria-hidden="true">→</span>
                <span class="rounded-full border border-zinc-200 bg-white px-4 py-1.5 font-medium text-zinc-700 shadow-sm dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">检查数据</span>
                <span class="text-zinc-300 dark:text-zinc-600" aria-hidden="true">→</span>
                <span class="rounded-full border border-zinc-200 bg-white px-4 py-1.5 font-medium text-zinc-700 shadow-sm dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">确认导入</span>
            </p>
        </div>
    </section>

    <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
        <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <h3 class="text-lg font-semibold">上传迁移文件</h3>
            <p class="mt-1 text-sm text-zinc-500">支持 XLSX、XLS、CSV；单文件最大 20MB，一批最多 5 个文件。</p>

            <div class="mt-4 flex flex-wrap gap-2">
                <flux:button wire:click="downloadStructureExample" size="sm" variant="ghost">
                    下载结构示例
                </flux:button>
                <flux:button
                    wire:click="downloadImportableSimulation"
                    size="sm"
                    variant="ghost"
                    :disabled="! $this->referenceReadiness['ready']"
                >
                    下载可导入模拟数据
                </flux:button>
            </div>
            <p class="mt-2 text-xs text-zinc-500">
                结构示例仅用于核对表头，不能导入；可导入模拟数据会使用当前已启用的基础数据生成。
                CSV 必须使用英文逗号（,）分隔。
            </p>

            <div class="mt-4 flex items-start gap-3 rounded-xl border px-4 py-3 text-sm {{ $this->referenceReadiness['ready'] ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-amber-200 bg-amber-50 text-amber-900' }}">
                @if ($this->referenceReadiness['ready'])
                    <flux:icon.check-circle class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
                @else
                    <flux:icon.exclamation-triangle class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
                @endif
                <div>
                    <p class="font-semibold">
                        基础数据状态：{{ $this->referenceReadiness['ready'] ? '已就绪' : '未就绪' }}
                    </p>
                    @if ($this->referenceReadiness['ready'])
                        <p class="mt-1 text-xs">
                            代理类型 {{ count($this->referenceReadiness['agent_types']) }} 项 ·
                            机构 {{ count($this->referenceReadiness['institutions']) }} 项 ·
                            直销来源 {{ count($this->referenceReadiness['direct_sales_sources']) }} 项
                        </p>
                    @else
                        <p class="mt-1 text-xs">{{ implode('、', $this->referenceReadiness['issues']) }}。请先补齐后再上传。</p>
                    @endif
                </div>
            </div>

            <form wire:submit="stageUploads" class="mt-5 space-y-4">
                <div
                    x-data="{ uploading: false, progress: 0 }"
                    x-on:livewire-upload-start="uploading = true"
                    x-on:livewire-upload-finish="uploading = false"
                    x-on:livewire-upload-error="uploading = false"
                    x-on:livewire-upload-cancel="uploading = false"
                    x-on:livewire-upload-progress="progress = $event.detail.progress"
                >
                    <label class="relative block cursor-pointer rounded-xl border-2 border-dashed border-zinc-300 bg-zinc-50/60 px-6 py-10 text-center transition hover:border-teal-400 hover:bg-teal-50/40 dark:border-zinc-600 dark:bg-zinc-800/40 dark:hover:border-teal-600">
                        <input
                            wire:model="uploads"
                            type="file"
                            multiple
                            accept=".xlsx,.xls,.csv"
                            class="absolute inset-0 h-full w-full cursor-pointer opacity-0"
                        >
                        <span class="pointer-events-none flex flex-col items-center gap-2">
                            <flux:icon.cloud-arrow-up class="size-9 text-zinc-400" aria-hidden="true" />
                            <span class="text-sm font-medium text-zinc-700 dark:text-zinc-200">选择文件 或 拖拽文件到此处</span>
                            <span class="text-xs text-zinc-500">支持 .xlsx、.xls、.csv 格式</span>
                        </span>
                    </label>
                    @if (! empty($uploads))
                        <ul class="mt-3 space-y-1.5">
                            @foreach ($uploads as $upload)
                                <li class="flex items-center gap-2 rounded-lg border border-zinc-200 bg-white px-3 py-2 text-xs text-zinc-700 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300">
                                    <flux:icon.document class="size-4 shrink-0 text-teal-600" aria-hidden="true" />
                                    <span class="truncate">{{ $upload->getClientOriginalName() }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    <div x-cloak x-show="uploading" class="mt-3" role="status">
                        <div class="flex items-center justify-between text-sm text-zinc-600">
                            <span>正在上传文件…</span>
                            <span x-text="`${progress}%`"></span>
                        </div>
                        <progress class="mt-2 h-2 w-full" max="100" x-bind:value="progress"></progress>
                    </div>
                </div>
                @error('uploads') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                @error('uploads.*') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                <p class="text-xs text-zinc-500">
                    客户编号格式：代理客户如 SZ-JG-0001；直销客户如 WEB-000001。
                </p>

                <flux:button
                    type="submit"
                    variant="primary"
                    wire:loading.attr="disabled"
                    wire:target="uploads,stageUploads"
                    :disabled="! $this->referenceReadiness['ready']"
                >
                    <span wire:loading.remove wire:target="stageUploads">上传文件并预览</span>
                    <span wire:loading wire:target="stageUploads">正在上传并检查…</span>
                </flux:button>
            </form>

            @if ($this->selectedBatch)
                @php($batch = $this->selectedBatch)
                <div
                    class="mt-8 border-t border-zinc-200 pt-6 dark:border-zinc-700"
                    @if ($uploads === [] && in_array($batch->status, [
                        \App\Modules\DataImport\Domain\ImportBatchStatus::Uploaded,
                        \App\Modules\DataImport\Domain\ImportBatchStatus::Parsing,
                    ], true))
                        wire:poll.5s
                    @endif
                >
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <h3 class="font-semibold">导入记录 <span class="text-sm font-normal text-zinc-400">#{{ $batch->id }}</span></h3>
                            <p class="mt-1 text-sm text-zinc-500">
                                状态：{{ $batchStatusLabels[$batch->status->value] ?? $batch->status->value }} ·
                                有效 {{ $batch->valid_rows }} ·
                                警告 {{ $batch->warning_rows }} ·
                                错误 {{ $batch->error_rows }}
                            </p>
                        </div>
                        <div class="flex gap-2">
                            @if ($batch->error_rows > 0 || $batch->warning_rows > 0)
                                <flux:button wire:click="downloadErrors" variant="ghost">下载错误报告</flux:button>
                                <flux:button wire:click="reparse" variant="ghost">重新检查</flux:button>
                            @endif
                            @if ($batch->status === \App\Modules\DataImport\Domain\ImportBatchStatus::Validated)
                                <flux:button wire:click="commitBatch" variant="primary" wire:confirm="检查已通过。确认将本批次数据正式导入系统吗？">
                                    确认导入
                                </flux:button>
                            @endif
                            @if ($batch->canRollback())
                                <flux:button wire:click="rollback" variant="danger" wire:confirm="确认撤销本次导入的全部数据吗？">
                                    撤销导入
                                </flux:button>
                            @endif
                        </div>
                    </div>

                    @if ($batch->failure_reason)
                        <p class="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ $batch->failure_reason }}</p>
                    @endif

                    <div class="mt-5 overflow-x-auto">
                        <table class="min-w-full text-left text-sm">
                            <thead class="border-b border-zinc-200 text-zinc-500">
                                <tr>
                                    <th class="px-3 py-2">文件</th>
                                    <th class="px-3 py-2">格式/编码</th>
                                    <th class="px-3 py-2">分隔符</th>
                                    <th class="px-3 py-2">识别结果</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-100">
                                @foreach ($batch->files as $file)
                                    @php($preflight = $file->preflight ?? [])
                                    <tr>
                                        <td class="px-3 py-2">
                                            <span class="font-medium">{{ $file->original_name }}</span>
                                            <span class="block text-xs text-zinc-500">{{ $fileStatusLabels[$file->status] ?? $file->status }}</span>
                                        </td>
                                        <td class="px-3 py-2">
                                            {{ $preflight['format'] ?? strtoupper($file->extension) }}
                                            @if (! empty($preflight['encoding']))
                                                <span class="block text-xs text-zinc-500">{{ $preflight['encoding'] }}</span>
                                            @endif
                                        </td>
                                        <td class="px-3 py-2">
                                            @if (($preflight['delimiter'] ?? null) === ',')
                                                英文逗号（,）
                                            @else
                                                不适用
                                            @endif
                                        </td>
                                        <td class="px-3 py-2">
                                            @forelse (($preflight['sheets'] ?? []) as $sheet)
                                                <span class="block">
                                                    {{ $sheet['name'] }}：
                                                    {{ $sheet['profile_label'] ?? '未识别' }}
                                                    · 表头第 {{ $sheet['header_row'] ?? '—' }} 行
                                                </span>
                                            @empty
                                                <span class="text-zinc-500">等待预检或文件读取失败</span>
                                            @endforelse
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-5 overflow-x-auto">
                        <table class="min-w-full text-left text-sm">
                            <thead class="border-b border-zinc-200 text-zinc-500">
                                <tr>
                                    <th class="px-3 py-2">文件/工作表</th>
                                    <th class="px-3 py-2">行</th>
                                    <th class="px-3 py-2">类型</th>
                                    <th class="px-3 py-2">状态</th>
                                    <th class="px-3 py-2">错误</th>
                                    <th class="px-3 py-2">人工处理</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-100">
                                @forelse ($batch->rows as $row)
                                    <tr>
                                        <td class="px-3 py-2">{{ $row->sheet_name ?: '默认工作表' }}</td>
                                        <td class="px-3 py-2">{{ $row->source_row }}</td>
                                        <td class="px-3 py-2">{{ $row->profile->label() }}</td>
                                        <td class="px-3 py-2">{{ $rowStatusLabels[$row->status->value] ?? $row->status->value }}</td>
                                        <td class="px-3 py-2 text-red-600">{{ implode('；', $row->errors ?? []) }}</td>
                                        <td class="min-w-64 px-3 py-2">
                                            @if (in_array($row->status, [
                                                \App\Modules\DataImport\Domain\ImportRowStatus::Error,
                                                \App\Modules\DataImport\Domain\ImportRowStatus::Warning,
                                                \App\Modules\DataImport\Domain\ImportRowStatus::DuplicateCandidate,
                                            ], true))
                                                <div class="flex gap-2">
                                                    <input
                                                        wire:model="ignoreReasons.{{ $row->id }}"
                                                        type="text"
                                                        placeholder="填写忽略原因"
                                                        class="min-w-40 rounded-lg border border-zinc-300 px-2 py-1"
                                                    >
                                                    <flux:button
                                                        size="sm"
                                                        wire:click="ignoreRow({{ $row->id }})"
                                                        wire:confirm="确认忽略该行且不导入？"
                                                    >忽略</flux:button>
                                                </div>
                                                @error("ignoreReasons.{$row->id}")
                                                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                                                @enderror
                                            @elseif ($row->resolution)
                                                <span class="text-zinc-500">{{ $row->resolution['reason'] ?? '已处理' }}</span>
                                            @else
                                                <span class="text-zinc-400">—</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="px-3 py-8 text-center text-zinc-500">解析中或没有业务数据行。</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </section>

        <div class="space-y-6">
            <aside class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <h3 class="font-semibold">高级映射设置<span class="ml-1 text-xs font-normal text-zinc-400">（可选）</span></h3>
                <p class="mt-1 text-xs text-zinc-500">补充映射以确保目标数据准确匹配，提升导入成功率。</p>

                <form wire:submit="saveInstitution" class="mt-4 space-y-2">
                    <input wire:model="institutionCode" placeholder="机构代码" class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm">
                    <input wire:model="institutionName" placeholder="机构正式名称" class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm">
                    <textarea wire:model="institutionAliases" placeholder="别名、缩写或换行分隔" class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm"></textarea>
                    <flux:button type="submit" size="sm">保存机构映射</flux:button>
                    @error('institutionCode') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                    @error('institutionName') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                </form>

                <form wire:submit="saveDirectSource" class="mt-5 space-y-2 border-t border-zinc-200 pt-4">
                    <input wire:model="directSourceCode" placeholder="直销代码（2-6位大写）" class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm">
                    <input wire:model="directSourceName" placeholder="直销来源正式名称" class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm">
                    <flux:button type="submit" size="sm">保存直销来源</flux:button>
                    @error('directSourceCode') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                    @error('directSourceName') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                </form>
            </aside>

            <aside class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <h3 class="font-semibold">最近导入记录</h3>
                <div class="mt-4 space-y-2">
                    @forelse ($this->batches as $batch)
                        <button
                            type="button"
                            wire:click="selectBatch('{{ $batch->id }}')"
                            class="w-full rounded-xl border px-3 py-3 text-left transition {{ $selectedBatchId === $batch->id ? 'border-teal-500 bg-teal-50 dark:bg-teal-950' : 'border-zinc-200 hover:border-zinc-300 dark:border-zinc-700' }}"
                        >
                            <span class="block break-all font-mono text-xs text-zinc-700 dark:text-zinc-300">{{ $batch->id }}</span>
                            <span class="mt-1.5 block text-sm">
                                <span class="font-medium {{ $batchStatusColors[$batch->status->value] ?? 'text-zinc-600' }}">{{ $batchStatusLabels[$batch->status->value] ?? $batch->status->value }}</span>
                                <span class="text-zinc-400"> · {{ $batch->files_count }} 文件</span>
                            </span>
                        </button>
                    @empty
                        <p class="text-sm text-zinc-500">还没有导入记录。</p>
                    @endforelse
                </div>
            </aside>
        </div>
    </div>
</div>
