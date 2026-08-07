<div>
    <section class="crm-section-header">
        <div>
            <h2 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-50">{{ __('search.page.title') }}</h2>
            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ __('search.page.description') }}</p>
        </div>
        <flux:button wire:click="downloadExport" wire:loading.attr="disabled" wire:target="downloadExport" variant="primary" icon="arrow-down-tray">{{ __('search.page.export') }}</flux:button>
    </section>

    <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <x-localized-date-picker wire:model.live.debounce.400ms="completedFrom" :value="$completedFrom" :label="__('search.page.fields.completed_from')" />
            <x-localized-date-picker wire:model.live.debounce.400ms="completedTo" :value="$completedTo" :label="__('search.page.fields.completed_to')" />
            <flux:input wire:model.live.debounce.400ms="timeFrom" type="time" :label="__('search.page.fields.time_from')" />
            <flux:input wire:model.live.debounce.400ms="timeTo" type="time" :label="__('search.page.fields.time_to')" />
            <flux:select wire:model.live="customerId" :label="__('search.page.fields.customer')">
                <option value="">{{ __('search.page.fields.all_customers') }}</option>
                @foreach ($options['customers'] as $customer)
                    <option value="{{ $customer['id'] }}">{{ $customer['name'] }}</option>
                @endforeach
            </flux:select>
            <flux:select wire:model.live="agentId" :label="__('search.page.fields.agent')">
                <option value="">{{ __('search.page.fields.all_agents') }}</option>
                @foreach ($options['agents'] as $agent)
                    <option value="{{ $agent['id'] }}">{{ $agent['name'] }}</option>
                @endforeach
            </flux:select>
            <flux:input wire:model.live.debounce.400ms="projectName" :label="__('search.page.fields.project')" />
            <flux:select wire:model.live="institutionId" :label="__('search.page.fields.institution')">
                <option value="">{{ __('search.page.fields.all_institutions') }}</option>
                @foreach ($options['institutions'] as $institution)
                    <option value="{{ $institution['id'] }}">{{ $institution['name'] }}</option>
                @endforeach
            </flux:select>
            <flux:input wire:model.live.debounce.400ms="translatorName" :label="__('search.page.fields.translator')" />
            <flux:input wire:model.live.debounce.400ms="amountMin" type="number" min="0" :label="__('search.page.fields.amount_min')" />
            <flux:input wire:model.live.debounce.400ms="amountMax" type="number" min="0" :label="__('search.page.fields.amount_max')" />
            <flux:input wire:model.live.debounce.400ms="passport" :label="__('search.page.fields.passport')" autocomplete="off" />
            <flux:select wire:model.live="sortField" :label="__('search.page.fields.sort_field')">
                <option value="completed_at">{{ __('search.page.fields.completed_at') }}</option>
                <option value="customer">{{ __('search.page.fields.customer') }}</option>
                <option value="agent">{{ __('search.page.fields.agent') }}</option>
                <option value="project">{{ __('search.page.fields.project') }}</option>
                <option value="institution">{{ __('search.page.fields.institution') }}</option>
                <option value="amount">{{ __('search.page.fields.amount') }}</option>
            </flux:select>
            <flux:select wire:model.live="sortDirection" :label="__('search.page.fields.sort_direction')">
                <option value="desc">{{ __('search.page.fields.descending') }}</option>
                <option value="asc">{{ __('search.page.fields.ascending') }}</option>
            </flux:select>
        </div>
        <div class="mt-4">
            <flux:button wire:click="clearFilters" variant="ghost">{{ __('search.page.clear') }}</flux:button>
        </div>
    </section>

    <section class="mt-6 rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h3 class="font-semibold">{{ __('search.page.results.title') }}</h3>
                <p class="text-sm text-zinc-500">{{ __('search.page.results.summary', ['total' => number_format($result['page']->total), 'milliseconds' => number_format($result['page']->queryMilliseconds, 2), 'per_page' => $result['page']->perPage]) }}</p>
            </div>
            <span class="text-sm text-zinc-500">{{ __('search.page.results.page', ['current' => $result['page']->currentPage, 'last' => max(1, $result['page']->lastPage)]) }}</span>
        </div>
        <div class="crm-table-wrap mt-4">
            <table class="crm-table">
                <thead>
                    <tr><th>{{ __('search.page.results.headers.completed_at') }}</th><th>{{ __('search.page.results.headers.customer') }}</th><th>{{ __('search.page.results.headers.agent') }}</th><th>{{ __('search.page.results.headers.project') }}</th><th>{{ __('search.page.results.headers.institution') }}</th><th>{{ __('search.page.results.headers.translator') }}</th><th>{{ __('search.page.results.headers.amount') }}</th></tr>
                </thead>
                <tbody>
                    @forelse ($result['rows'] as $row)
                        <tr>
                            <td>{{ $row['completed_at'] }} @if ($row['completion_precision'] === 'date')<span class="text-xs text-zinc-400">{{ __('search.page.results.date_precision') }}</span>@endif</td>
                            <td><a href="{{ route('customers.show', $row['customer_id']) }}" class="font-semibold text-teal-700 hover:underline" wire:navigate>{{ $row['customer'] }}</a></td>
                            <td>{{ $row['agent'] }}</td>
                            <td>{{ $row['project'] }}</td>
                            <td>{{ $row['institution'] }}</td>
                            <td>{{ $row['translator'] ?: '—' }}</td>
                            <td>₩ {{ number_format($row['amount_krw']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-10 text-center text-zinc-500">{{ __('search.page.results.empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4 flex justify-end gap-2">
            <flux:button wire:click="previousPage" variant="ghost" :disabled="$result['page']->currentPage <= 1">{{ __('search.page.results.previous') }}</flux:button>
            <flux:button wire:click="nextPage({{ $result['page']->lastPage }})" variant="ghost" :disabled="$result['page']->currentPage >= $result['page']->lastPage">{{ __('search.page.results.next') }}</flux:button>
        </div>
    </section>

    <section class="mt-6 rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900" @if ($recentExports->contains(fn ($export) => in_array($export->status, ['queued', 'generating'], true))) wire:poll.3s @endif>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h3 class="font-semibold">{{ __('search.page.exports.title') }}</h3>
                <p class="mt-1 text-sm text-zinc-500">{{ __('search.page.exports.description') }}</p>
            </div>
            <span class="text-sm text-zinc-500">{{ __('search.page.exports.count', ['count' => $recentExports->count()]) }}</span>
        </div>
        <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                @forelse ($recentExports as $export)
                    <div class="rounded-xl border border-zinc-200 p-4 text-sm dark:border-zinc-700">
                        <div class="flex items-center justify-between gap-3">
                            <span>{{ $export->created_at?->format('Y-m-d H:i') }} · {{ __('search.page.exports.statuses')[$export->status] ?? $export->status }}</span>
                            @if ($export->status === 'completed' && $export->expires_at->isFuture())
                                <a href="{{ route('reports.exports.download', $export) }}" class="font-semibold text-teal-700">{{ __('search.page.exports.download') }}</a>
                            @elseif ($export->status === 'failed' && $export->expires_at->isFuture())
                                <flux:button wire:click="retryExport('{{ $export->id }}')" variant="ghost" size="sm">{{ __('search.page.exports.retry') }}</flux:button>
                            @endif
                        </div>
                        @if ($export->localized_failure_reason)<p class="mt-1 text-red-600">{{ $export->localized_failure_reason }}</p>@endif
                        @if ($export->sha256)<p class="mt-1 break-all font-mono text-xs text-zinc-400">SHA-256 {{ $export->sha256 }}</p>@endif
                    </div>
                @empty
                    <p class="text-sm text-zinc-500">{{ __('search.page.exports.empty') }}</p>
                @endforelse
        </div>
    </section>
</div>
