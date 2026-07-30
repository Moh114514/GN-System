<div class="crm-content">
    <section class="crm-section-header">
        <div>
            <p class="crm-eyebrow">Phase 2 · 数据迁移</p>
            <h2>历史数据导入</h2>
            <p>上传后先进入加密预处理区；只有零错误、零待处理项的批次才能正式写入。</p>
        </div>
    </section>

    @if (session('status'))
        <div class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ session('status') }}
        </div>
    @endif

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

            <div class="mt-4 rounded-xl border px-4 py-3 text-sm {{ $this->referenceReadiness['ready'] ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-amber-200 bg-amber-50 text-amber-900' }}">
                <p class="font-semibold">
                    导入基础数据：{{ $this->referenceReadiness['ready'] ? '已就绪' : '未就绪' }}
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

            <form wire:submit="stageUploads" class="mt-5 space-y-4">
                <div
                    x-data="{ uploading: false, progress: 0 }"
                    x-on:livewire-upload-start="uploading = true"
                    x-on:livewire-upload-finish="uploading = false"
                    x-on:livewire-upload-error="uploading = false"
                    x-on:livewire-upload-cancel="uploading = false"
                    x-on:livewire-upload-progress="progress = $event.detail.progress"
                >
                    <input
                        wire:model="uploads"
                        type="file"
                        multiple
                        accept=".xlsx,.xls,.csv"
                        class="block w-full rounded-xl border border-dashed border-zinc-300 p-5 text-sm"
                    >
                    <div x-cloak x-show="uploading" class="mt-3" role="status">
                        <div class="flex items-center justify-between text-sm text-zinc-600">
                            <span>正在上传到加密预处理区…</span>
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
                    <span wire:loading.remove wire:target="stageUploads">加密上传并预演</span>
                    <span wire:loading wire:target="stageUploads">正在创建导入批次…</span>
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
                            <h3 class="font-semibold">批次 {{ $batch->id }}</h3>
                            <p class="mt-1 text-sm text-zinc-500">
                                状态：{{ $batch->status->value }} ·
                                有效 {{ $batch->valid_rows }} ·
                                警告 {{ $batch->warning_rows }} ·
                                错误 {{ $batch->error_rows }}
                            </p>
                        </div>
                        <div class="flex gap-2">
                            @if ($batch->error_rows > 0 || $batch->warning_rows > 0)
                                <flux:button wire:click="downloadErrors" variant="ghost">下载错误报告</flux:button>
                                <flux:button wire:click="reparse" variant="ghost">重新校验</flux:button>
                            @endif
                            @if ($batch->status === \App\Modules\DataImport\Domain\ImportBatchStatus::Validated)
                                <flux:button wire:click="commitBatch" variant="primary" wire:confirm="确认将整个批次原子写入正式业务表？">
                                    正式导入
                                </flux:button>
                            @endif
                            @if ($batch->canRollback())
                                <flux:button wire:click="rollback" variant="danger" wire:confirm="确认回滚该批次的全部导入数据？">
                                    回滚批次
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
                                            <span class="block text-xs text-zinc-500">{{ $file->status }}</span>
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
                                    <th class="px-3 py-2">人工裁决</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-100">
                                @forelse ($batch->rows as $row)
                                    <tr>
                                        <td class="px-3 py-2">{{ $row->sheet_name ?: '默认工作表' }}</td>
                                        <td class="px-3 py-2">{{ $row->source_row }}</td>
                                        <td class="px-3 py-2">{{ $row->profile->value }}</td>
                                        <td class="px-3 py-2">{{ $row->status->value }}</td>
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

        <aside class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <h3 class="font-semibold">导入映射</h3>
            <p class="mt-1 text-xs text-zinc-500">补充未知机构或直销来源后，重新校验当前批次。</p>

            <form wire:submit="saveInstitution" class="mt-4 space-y-2">
                <input wire:model="institutionCode" placeholder="机构代码" class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm">
                <input wire:model="institutionName" placeholder="机构正式名称" class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm">
                <textarea wire:model="institutionAliases" placeholder="别名，逗号或换行分隔" class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm"></textarea>
                <flux:button type="submit" size="sm">保存机构映射</flux:button>
                @error('institutionCode') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                @error('institutionName') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
            </form>

            <form wire:submit="saveDirectSource" class="mt-5 space-y-2 border-t border-zinc-200 pt-4">
                <input wire:model="directSourceCode" placeholder="直销代码（2–6位大写）" class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm">
                <input wire:model="directSourceName" placeholder="直销来源正式名称" class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm">
                <flux:button type="submit" size="sm">保存直销来源</flux:button>
                @error('directSourceCode') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                @error('directSourceName') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
            </form>

            <h3 class="mt-7 border-t border-zinc-200 pt-5 font-semibold">最近批次</h3>
            <div class="mt-4 space-y-2">
                @forelse ($this->batches as $batch)
                    <button
                        type="button"
                        wire:click="selectBatch('{{ $batch->id }}')"
                        class="w-full rounded-xl border px-3 py-3 text-left text-sm {{ $selectedBatchId === $batch->id ? 'border-blue-500 bg-blue-50' : 'border-zinc-200' }}"
                    >
                        <strong class="block truncate">{{ $batch->id }}</strong>
                        <span class="mt-1 block text-zinc-500">{{ $batch->status->value }} · {{ $batch->files_count }} 文件</span>
                    </button>
                @empty
                    <p class="text-sm text-zinc-500">还没有导入批次。</p>
                @endforelse
            </div>
        </aside>
    </div>
</div>
