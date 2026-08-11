<div @if (in_array($this->selectedBatch?->status, [\App\Modules\DataImport\Domain\ImportBatchStatus::Uploaded, \App\Modules\DataImport\Domain\ImportBatchStatus::Parsing], true)) wire:poll.3s @endif>
    <x-page-back :href="route('configuration.data-maintenance')" :label="__('imports.back')" class="mb-4" />

    @php
        $batchStatusLabels = __('imports.statuses.batch');
        $stageLabels = __('imports.stages.names');
        $stageStatusLabels = __('imports.stages.statuses');
        $stageMetricLabels = __('imports.stages.metrics');
    @endphp
    <section class="crm-section-header">
        <div>
            <p class="text-xs font-medium text-zinc-400">{{ __('imports.reference.eyebrow') }}</p>
            <h2 class="mt-1 text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-50">{{ __('imports.reference.title') }}</h2>
            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ __('imports.reference.description') }}</p>
            <p class="mt-4 flex flex-wrap items-center gap-x-3 gap-y-2 text-sm">
                <span class="rounded-full border border-zinc-200 bg-white px-4 py-1.5 font-medium text-zinc-700 shadow-sm dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">{{ __('imports.reference.steps.upload') }}</span>
                <span class="text-zinc-300 dark:text-zinc-600" aria-hidden="true">→</span>
                <span class="rounded-full border border-zinc-200 bg-white px-4 py-1.5 font-medium text-zinc-700 shadow-sm dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">{{ __('imports.reference.steps.check') }}</span>
                <span class="text-zinc-300 dark:text-zinc-600" aria-hidden="true">→</span>
                <span class="rounded-full border border-zinc-200 bg-white px-4 py-1.5 font-medium text-zinc-700 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">{{ __('imports.reference.steps.confirm') }}</span>
            </p>
        </div>
        <flux:button wire:click="downloadExample" variant="ghost" icon="arrow-down-tray">{{ __('imports.reference.download_example') }}</flux:button>
    </section>

    <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <h3 class="text-lg font-semibold">{{ __('imports.reference.upload_title') }}</h3>
        <p class="mt-1 text-sm text-zinc-600">{{ __('imports.reference.upload_description') }}</p>
        <form wire:submit="stageWorkbook" class="mt-5 space-y-4">
            <flux:select wire:model="operationMode" :label="__('imports.reference.operation_mode_label')">
                <flux:select.option value="normal">{{ __('imports.reference.operation_modes.normal') }}</flux:select.option>
                <flux:select.option value="historical_correction">{{ __('imports.reference.operation_modes.historical_correction') }}</flux:select.option>
            </flux:select>
            <flux:textarea wire:model="operationReason" :label="__('imports.reference.operation_reason_label')" :placeholder="__('imports.reference.operation_reason_placeholder')" />
            @error('operationMode') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
            @error('operationReason') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
            <flux:input wire:model="workbook" type="file" accept=".xlsx" :label="__('imports.reference.workbook_label')" />
            @error('workbook') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
            <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="workbook,stageWorkbook">
                {{ __('imports.reference.submit') }}
            </flux:button>
        </form>
    </section>

    <section class="mt-6 grid gap-6 xl:grid-cols-[20rem_1fr]">
        <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <h3 class="font-semibold">{{ __('imports.reference.batches') }}</h3>
            <div class="mt-4 space-y-2">
                @forelse ($this->batches as $batch)
                    <button type="button" wire:click="selectBatch('{{ $batch->id }}')" class="w-full rounded-xl border p-3 text-left {{ $selectedBatchId === $batch->id ? 'border-teal-500 bg-teal-50' : 'border-zinc-200' }}">
                        <span class="flex items-center justify-between gap-2">
                            <strong>{{ $batchStatusLabels[$batch->status->value] ?? $batch->status->value }}</strong>
                            <span class="text-xs text-zinc-400">{{ $batch->created_at->format('Y-m-d H:i') }}</span>
                        </span>
                        <span class="mt-1 block text-sm text-zinc-500">{{ __('imports.reference.rows_summary', ['valid' => $batch->valid_rows, 'errors' => $batch->error_rows, 'total' => $batch->total_rows]) }}</span>
                        <span class="mt-0.5 block text-xs text-zinc-500">{{ __('imports.reference.operation_mode') }}: {{ __('imports.reference.operation_modes.'.($batch->operation_mode?->value ?? 'normal')) }}</span>
                        <span class="mt-0.5 block truncate text-xs text-zinc-400">#{{ $batch->id }}</span>
                    </button>
                @empty
                    <p class="text-sm text-zinc-500">{{ __('imports.reference.empty_batches') }}</p>
                @endforelse
            </div>
        </div>

        <div class="space-y-5">
            @if ($this->selectedBatch)
                @php($batch = $this->selectedBatch)
                <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h3 class="font-semibold">{{ __('imports.reference.preview') }}</h3>
                            <p class="mt-1 text-sm text-zinc-600">{{ __('imports.reference.rows_summary', ['valid' => $batch->valid_rows, 'errors' => $batch->error_rows, 'total' => $batch->total_rows]) }}</p>
                            <p class="mt-1 text-sm text-zinc-500">{{ __('imports.reference.operation_mode') }}: {{ __('imports.reference.operation_modes.'.($batch->operation_mode?->value ?? 'normal')) }}</p>
                            @if ($batch->operation_reason)
                                <p class="mt-1 text-sm text-zinc-500">{{ __('imports.reference.operation_reason') }}: {{ $batch->operation_reason }}</p>
                            @endif
                        </div>
                        <div class="flex flex-wrap gap-2">
                            @if ($batch->error_rows > 0 || $batch->failure_reason)
                                <flux:button wire:click="downloadErrors" variant="ghost" icon="arrow-down-tray">
                                    {{ __('imports.reference.download_errors') }}
                                </flux:button>
                            @endif
                            @if (in_array($batch->status, [\App\Modules\DataImport\Domain\ImportBatchStatus::Failed, \App\Modules\DataImport\Domain\ImportBatchStatus::NeedsReview], true))
                                <flux:button wire:click="reparse" variant="ghost">{{ __('imports.reference.reparse') }}</flux:button>
                            @endif
                        </div>
                    </div>

                    @if ($batch->failure_reason)
                        <div class="mt-4 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                            <p class="font-semibold">{{ __('imports.reference.failure_title') }}</p>
                            <p class="mt-1">{{ app(\App\Modules\DataImport\Application\Services\ImportIssueMessagePresenter::class)->presentBatch($batch) }}</p>
                            <p class="mt-2 text-red-600">{{ __('imports.reference.failure_hint') }}</p>
                        </div>
                    @elseif ($batch->error_rows > 0)
                        <div class="mt-4 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                            <p class="font-semibold">{{ __('imports.reference.row_error', ['count' => $batch->error_rows]) }}</p>
                            <p class="mt-1">{{ __('imports.reference.row_error_hint') }}</p>
                        </div>
                    @endif

                    @if (! empty(($batch->summary ?? [])['stages']))
                        <div class="mt-5 overflow-x-auto">
                            <table class="min-w-full text-left text-sm">
                                <thead class="border-b border-zinc-200 text-zinc-500"><tr><th class="px-3 py-2">{{ __('imports.tables.stage') }}</th><th class="px-3 py-2">{{ __('imports.tables.status') }}</th><th class="px-3 py-2">{{ __('imports.tables.metrics') }}</th></tr></thead>
                                <tbody class="divide-y divide-zinc-100">
                                    @foreach (($batch->summary['stages'] ?? []) as $stage => $state)
                                        <tr><td class="px-3 py-2 font-medium">{{ $stageLabels[$stage] ?? $stage }}</td><td class="px-3 py-2">{{ $stageStatusLabels[$state['status'] ?? 'pending'] ?? ($state['status'] ?? 'pending') }}</td><td class="px-3 py-2 text-zinc-500">{{ collect($state)->except('status')->map(fn ($value, $key) => ($stageMetricLabels[$key] ?? $key).': '.$value)->implode(' / ') ?: '-' }}</td></tr>
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
                                        <span class="block text-xs text-zinc-500">{{ __('imports.reference.sheet_header', ['row' => $sheet['header_row']]) }}</span>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    @endforeach
                </section>

                <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <h3 class="font-semibold">{{ __('imports.reference.rows_title') }}</h3>
                    <div class="crm-table-wrap mt-4">
                        <table class="crm-table">
                            <thead><tr><th>{{ __('imports.reference.headers.source') }}</th><th>{{ __('imports.reference.headers.result') }}</th><th>{{ __('imports.reference.headers.preview') }}</th><th>{{ __('imports.reference.headers.details') }}</th></tr></thead>
                            <tbody>
                                @forelse ($batch->rows as $row)
                                    <tr>
                                        <td>{{ $row->sheet_name }} #{{ $row->source_row }}</td>
                                        <td>
                                            @if ($row->status === \App\Modules\DataImport\Domain\ImportRowStatus::Valid)
                                                <span class="text-emerald-700">{{ __('imports.reference.valid') }}</span>
                                            @else
                                                <span class="font-medium text-red-700">{{ __('imports.reference.error') }}</span>
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
                                            @foreach ($batch->issues->where('import_row_id', $row->id) as $issue)
                                                <span class="block">{{ app(\App\Modules\DataImport\Application\Services\ImportIssueMessagePresenter::class)->present($issue) }}</span>
                                            @endforeach
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="py-8 text-center text-zinc-500">{{ __('imports.reference.no_preview') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </section>

                @if ($batch->status === \App\Modules\DataImport\Domain\ImportBatchStatus::Validated)
                    <section class="rounded-2xl border border-amber-300 bg-amber-50 p-5">
                        <h3 class="font-semibold text-amber-900">{{ __('imports.reference.admin_confirm') }}</h3>
                        <p class="mt-1 text-sm text-amber-800">{{ __('imports.reference.admin_confirm_hint') }}</p>
                        <div class="mt-4">
                            <flux:checkbox wire:model="confirmImport" :label="__('imports.reference.confirm_label')" />
                            @error('confirmImport') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <flux:button wire:click="commitBatch" variant="primary" class="mt-4" :wire:confirm="__('imports.reference.confirm_prompt')">{{ __('imports.reference.confirm') }}</flux:button>
                    </section>
                @endif
            @else
                <section class="rounded-2xl border border-dashed border-zinc-300 p-10 text-center text-zinc-500">{{ __('imports.reference.empty_batch') }}</section>
            @endif
        </div>
    </section>
</div>
