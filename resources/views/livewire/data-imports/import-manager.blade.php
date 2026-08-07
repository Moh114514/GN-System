<div class="crm-content">
    <x-page-back
        :href="route('configuration.data-maintenance')"
        :label="__('imports.back')"
        class="mb-4"
    />

    @php
        $batchStatusLabels = __('imports.statuses.batch');
        $rowStatusLabels = __('imports.statuses.row');
        $fileStatusLabels = __('imports.statuses.file');
        $stageLabels = __('imports.stages.names');
        $stageStatusLabels = __('imports.stages.statuses');
        $stageMetricLabels = __('imports.stages.metrics');
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
            <h2 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-50">{{ __('imports.historical.title') }}</h2>
            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ __('imports.historical.description') }}</p>
            <p class="mt-4 flex flex-wrap items-center gap-x-3 gap-y-2 text-sm">
                <span class="rounded-full border border-zinc-200 bg-white px-4 py-1.5 font-medium text-zinc-700 shadow-sm dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">{{ __('imports.historical.steps.upload') }}</span>
                <span class="text-zinc-300 dark:text-zinc-600" aria-hidden="true">→</span>
                <span class="rounded-full border border-zinc-200 bg-white px-4 py-1.5 font-medium text-zinc-700 shadow-sm dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">{{ __('imports.historical.steps.check') }}</span>
                <span class="text-zinc-300 dark:text-zinc-600" aria-hidden="true">→</span>
                <span class="rounded-full border border-zinc-200 bg-white px-4 py-1.5 font-medium text-zinc-700 shadow-sm dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">{{ __('imports.historical.steps.confirm') }}</span>
            </p>
        </div>
    </section>

    <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
        <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <h3 class="text-lg font-semibold">{{ __('imports.historical.upload.title') }}</h3>
            <p class="mt-1 text-sm text-zinc-500">{{ __('imports.historical.upload.description') }}</p>

            <div class="mt-4 flex flex-wrap gap-2">
                <flux:button wire:click="downloadStructureExample" size="sm" variant="ghost">
                    {{ __('imports.historical.upload.structure_example') }}
                </flux:button>
                <flux:button
                    wire:click="downloadImportableSimulation"
                    size="sm"
                    variant="ghost"
                    :disabled="! $this->referenceReadiness['ready']"
                >
                    {{ __('imports.historical.upload.simulation') }}
                </flux:button>
            </div>
            <p class="mt-2 text-xs text-zinc-500">
                {{ __('imports.historical.upload.note') }}
            </p>

            <div class="mt-4 flex items-start gap-3 rounded-xl border px-4 py-3 text-sm {{ $this->referenceReadiness['ready'] ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-amber-200 bg-amber-50 text-amber-900' }}">
                @if ($this->referenceReadiness['ready'])
                    <flux:icon.check-circle class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
                @else
                    <flux:icon.exclamation-triangle class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
                @endif
                <div>
                    <p class="font-semibold">
                        {{ __('imports.historical.upload.ready', ['status' => $this->referenceReadiness['ready'] ? __('imports.historical.upload.ready_status') : __('imports.historical.upload.not_ready_status')]) }}
                    </p>
                    @if ($this->referenceReadiness['ready'])
                        <p class="mt-1 text-xs">
                            {{ __('imports.historical.upload.counts', ['agent_types' => count($this->referenceReadiness['agent_types']), 'institutions' => count($this->referenceReadiness['institutions']), 'direct_sales_sources' => count($this->referenceReadiness['direct_sales_sources'])]) }}
                        </p>
                    @else
                        <p class="mt-1 text-xs">{{ __('imports.historical.upload.not_ready_hint', ['issues' => implode('、', $this->referenceReadiness['issues'])]) }}</p>
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
                            <span class="text-sm font-medium text-zinc-700 dark:text-zinc-200">{{ __('imports.historical.upload.choose_files') }}</span>
                            <span class="text-xs text-zinc-500">{{ __('imports.historical.upload.formats') }}</span>
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
                            <span>{{ __('imports.historical.upload.uploading') }}</span>
                            <span x-text="`${progress}%`"></span>
                        </div>
                        <progress class="mt-2 h-2 w-full" max="100" x-bind:value="progress"></progress>
                    </div>
                </div>
                @error('uploads') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                @error('uploads.*') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                <p class="text-xs text-zinc-500">
                    {{ __('imports.historical.upload.customer_code_hint') }}
                </p>

                <flux:button
                    type="submit"
                    variant="primary"
                    wire:loading.attr="disabled"
                    wire:target="uploads,stageUploads"
                    :disabled="! $this->referenceReadiness['ready']"
                >
                    <span wire:loading.remove wire:target="stageUploads">{{ __('imports.historical.upload.submit') }}</span>
                    <span wire:loading wire:target="stageUploads">{{ __('imports.historical.upload.submitting') }}</span>
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
                            <h3 class="font-semibold">{{ __('imports.historical.record.title') }} <span class="text-sm font-normal text-zinc-400">#{{ $batch->id }}</span></h3>
                            <p class="mt-1 text-sm text-zinc-500">
                                {{ __('imports.historical.record.status', ['status' => $batchStatusLabels[$batch->status->value] ?? $batch->status->value]) }} ·
                                {{ __('imports.historical.record.valid', ['count' => $batch->valid_rows]) }} ·
                                {{ __('imports.historical.record.warnings', ['count' => $batch->warning_rows]) }} ·
                                {{ __('imports.historical.record.errors', ['count' => $batch->error_rows]) }}
                            </p>
                        </div>
                        <div class="flex gap-2">
                            @if ($batch->error_rows > 0 || $batch->warning_rows > 0 || $batch->failure_reason || $batch->issues->isNotEmpty())
                                <flux:button wire:click="downloadErrors" variant="ghost">{{ __('imports.historical.record.download_errors') }}</flux:button>
                                <flux:button wire:click="reparse" variant="ghost">{{ __('imports.historical.record.reparse') }}</flux:button>
                            @endif
                            @if ($batch->status === \App\Modules\DataImport\Domain\ImportBatchStatus::Validated)
                                <flux:button wire:click="commitBatch" variant="primary" :wire:confirm="__('imports.historical.record.confirm_prompt')">
                                    {{ __('imports.historical.record.confirm') }}
                                </flux:button>
                            @endif
                            @if ($batch->canRollback())
                                <flux:button wire:click="rollback" variant="danger" :wire:confirm="__('imports.historical.record.rollback_prompt')">
                                    {{ __('imports.historical.record.rollback') }}
                                </flux:button>
                            @endif
                        </div>
                    </div>

                    @if ($batch->failure_reason)
                        <p class="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ app(\App\Modules\DataImport\Application\Services\ImportIssueMessagePresenter::class)->presentBatch($batch) }}</p>
                    @endif

                    @if (! empty(($batch->summary ?? [])['stages']))
                        <div class="mt-5 overflow-x-auto">
                            <table class="min-w-full text-left text-sm">
                                <thead class="border-b border-zinc-200 text-zinc-500">
                                    <tr>
                                        <th class="px-3 py-2">{{ __('imports.tables.stage') }}</th>
                                        <th class="px-3 py-2">{{ __('imports.tables.status') }}</th>
                                        <th class="px-3 py-2">{{ __('imports.tables.metrics') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-100">
                                    @foreach (($batch->summary['stages'] ?? []) as $stage => $state)
                                        <tr>
                                            <td class="px-3 py-2 font-medium">{{ $stageLabels[$stage] ?? $stage }}</td>
                                            <td class="px-3 py-2">{{ $stageStatusLabels[$state['status'] ?? 'pending'] ?? ($state['status'] ?? 'pending') }}</td>
                                            <td class="px-3 py-2 text-zinc-500">
                                                {{ collect($state)->except('status')->map(fn ($value, $key) => ($stageMetricLabels[$key] ?? $key).': '.$value)->implode(' / ') ?: '-' }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif

                    <div class="mt-5 overflow-x-auto">
                        <table class="min-w-full text-left text-sm">
                            <thead class="border-b border-zinc-200 text-zinc-500">
                                <tr>
                                    <th class="px-3 py-2">{{ __('imports.historical.tables.file') }}</th>
                                    <th class="px-3 py-2">{{ __('imports.historical.tables.format') }}</th>
                                    <th class="px-3 py-2">{{ __('imports.historical.tables.delimiter') }}</th>
                                    <th class="px-3 py-2">{{ __('imports.historical.tables.detected') }}</th>
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
                                                {{ __('imports.historical.tables.english_comma') }}
                                            @else
                                                {{ __('imports.historical.tables.not_applicable') }}
                                            @endif
                                        </td>
                                        <td class="px-3 py-2">
                                            @forelse (($preflight['sheets'] ?? []) as $sheet)
                                                <span class="block">
                                                    {{ $sheet['name'] }}：
                                                     {{ $sheet['profile_label'] ?? __('imports.historical.tables.unrecognized') }}
                                                     · {{ __('imports.historical.tables.sheet_header', ['row' => $sheet['header_row'] ?? '—']) }}
                                                </span>
                                            @empty
                                                <span class="text-zinc-500">{{ __('imports.historical.tables.preflight_waiting') }}</span>
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
                                    <th class="px-3 py-2">{{ __('imports.historical.tables.file_sheet') }}</th>
                                    <th class="px-3 py-2">{{ __('imports.historical.tables.row') }}</th>
                                    <th class="px-3 py-2">{{ __('imports.historical.tables.type') }}</th>
                                    <th class="px-3 py-2">{{ __('imports.historical.tables.status') }}</th>
                                    <th class="px-3 py-2">{{ __('imports.historical.tables.error') }}</th>
                                    <th class="px-3 py-2">{{ __('imports.historical.tables.manual') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-100">
                                @forelse ($batch->rows as $row)
                                    <tr>
                                        <td class="px-3 py-2">{{ $row->sheet_name ?: __('imports.historical.tables.default_sheet') }}</td>
                                        <td class="px-3 py-2">{{ $row->source_row }}</td>
                                        <td class="px-3 py-2">{{ $row->profile->label() }}</td>
                                        <td class="px-3 py-2">{{ $rowStatusLabels[$row->status->value] ?? $row->status->value }}</td>
                                        <td class="px-3 py-2 text-red-600">
                                            @foreach ($batch->issues->where('import_row_id', $row->id) as $issue)
                                                <span class="block">{{ app(\App\Modules\DataImport\Application\Services\ImportIssueMessagePresenter::class)->present($issue) }}</span>
                                            @endforeach
                                        </td>
                                        <td class="min-w-64 px-3 py-2">
                                            @php($rowIssues = $batch->issues->where('import_row_id', $row->id))
                                            @php($canIgnore = $rowIssues->isNotEmpty() && $rowIssues->every(fn ($issue) => $issue->is_ignorable))
                                            @if (in_array($row->status, [
                                                \App\Modules\DataImport\Domain\ImportRowStatus::Error,
                                                \App\Modules\DataImport\Domain\ImportRowStatus::Warning,
                                                \App\Modules\DataImport\Domain\ImportRowStatus::DuplicateCandidate,
                                            ], true) && $canIgnore)
                                                <div class="flex gap-2">
                                                    <input
                                                        wire:model="ignoreReasons.{{ $row->id }}"
                                                        type="text"
                                                         :placeholder="__('imports.historical.tables.fill_ignore_reason')"
                                                        class="min-w-40 rounded-lg border border-zinc-300 px-2 py-1"
                                                    >
                                                    <flux:button
                                                        size="sm"
                                                        wire:click="ignoreRow({{ $row->id }})"
                                                         :wire:confirm="__('imports.historical.tables.ignore_prompt')"
                                                     >{{ __('imports.historical.tables.ignore') }}</flux:button>
                                                </div>
                                                @error("ignoreReasons.{$row->id}")
                                                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                                                @enderror
                                            @elseif ($row->resolution)
                                                <span class="text-zinc-500">{{ $row->resolution['reason'] ?? __('imports.historical.tables.resolved') }}</span>
                                            @else
                                                <span class="text-zinc-400">—</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="px-3 py-8 text-center text-zinc-500">{{ __('imports.historical.tables.no_preview') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </section>

        <div class="space-y-6">
            <aside class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <h3 class="font-semibold">{{ __('imports.historical.mapping.title') }}<span class="ml-1 text-xs font-normal text-zinc-400">{{ __('imports.historical.mapping.optional') }}</span></h3>
                <p class="mt-1 text-xs text-zinc-500">{{ __('imports.historical.mapping.description') }}</p>

                <form wire:submit="saveInstitution" class="mt-4 space-y-2">
                    <input wire:model="institutionCode" :placeholder="__('imports.historical.mapping.institution_code')" class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm">
                    <input wire:model="institutionName" :placeholder="__('imports.historical.mapping.institution_name')" class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm">
                    <textarea wire:model="institutionAliases" :placeholder="__('imports.historical.mapping.institution_aliases')" class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm"></textarea>
                    <flux:button type="submit" size="sm">{{ __('imports.historical.mapping.save_institution') }}</flux:button>
                    @error('institutionCode') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                    @error('institutionName') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                </form>

                <form wire:submit="saveDirectSource" class="mt-5 space-y-2 border-t border-zinc-200 pt-4">
                    <input wire:model="directSourceCode" :placeholder="__('imports.historical.mapping.direct_code')" class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm">
                    <input wire:model="directSourceName" :placeholder="__('imports.historical.mapping.direct_name')" class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm">
                    <flux:button type="submit" size="sm">{{ __('imports.historical.mapping.save_direct') }}</flux:button>
                    @error('directSourceCode') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                    @error('directSourceName') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                </form>
            </aside>

            <aside class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <h3 class="font-semibold">{{ __('imports.historical.mapping.recent') }}</h3>
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
                                <span class="text-zinc-400"> · {{ __('imports.historical.mapping.files', ['count' => $batch->files_count]) }}</span>
                            </span>
                        </button>
                    @empty
                        <p class="text-sm text-zinc-500">{{ __('imports.historical.mapping.empty') }}</p>
                    @endforelse
                </div>
            </aside>
        </div>
    </div>
</div>
