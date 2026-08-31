<div>
    <section class="crm-section-header mb-6">
        <div>
            <h2 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-50">{{ __('institution_sales.title') }}</h2>
            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ __('institution_sales.description') }}</p>
        </div>
        <flux:button wire:click="downloadExport" wire:loading.attr="disabled" wire:target="downloadExport" variant="primary">
            {{ __('institution_sales.export.button') }}
        </flux:button>
    </section>

    <section class="mb-6 rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <div class="grid gap-4 md:grid-cols-2">
            <flux:input wire:model.live="month" type="month" :label="__('institution_sales.fields.month')" />
            <flux:input wire:model.live.debounce.400ms="institutionSearch" :label="__('institution_sales.fields.institution_search')" :placeholder="__('institution_sales.fields.institution_search_placeholder')" />
        </div>
        @error('month')
            <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
        @enderror
        <p class="mt-3 text-sm text-zinc-500 dark:text-zinc-400">
            {{ __('institution_sales.scope_note') }} · {{ __('institution_sales.period', ['month' => $summary['month']]) }}
        </p>
    </section>

    <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h3 class="text-lg font-bold">{{ __('institution_sales.table.title') }}</h3>
                <p class="mt-1 text-sm text-zinc-500">{{ __('institution_sales.table.summary', ['count' => count($summary['rows'])]) }}</p>
            </div>
            <div class="text-right text-sm text-zinc-500">
                <div>{{ __('institution_sales.table.total_customers') }}：<strong class="text-zinc-900 dark:text-zinc-50">{{ number_format($summary['total_customers']) }}</strong></div>
                <div>{{ __('institution_sales.table.total_orders') }}：<strong class="text-zinc-900 dark:text-zinc-50">{{ number_format($summary['total_orders']) }}</strong></div>
                <div>{{ __('institution_sales.table.total_amount') }}：<strong class="text-zinc-900 dark:text-zinc-50">₩ {{ number_format($summary['total_amount_krw']) }}</strong></div>
            </div>
        </div>

        <div class="crm-table-wrap mt-4">
            <table class="crm-table min-w-[680px]">
                <thead>
                    <tr>
                        <th class="text-right">{{ __('institution_sales.table.number') }}</th>
                        <th>{{ __('institution_sales.table.institution') }}</th>
                        <th class="text-right">{{ __('institution_sales.table.customers') }}</th>
                        <th class="text-right">{{ __('institution_sales.table.orders') }}</th>
                        <th class="text-right">{{ __('institution_sales.table.amount') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($summary['rows'] as $index => $row)
                        <tr wire:key="institution-sales-{{ $row['institution_id'] }}">
                            <td class="text-right">{{ $index + 1 }}</td>
                            <td class="font-semibold">{{ $row['institution'] }}</td>
                            <td class="text-right">{{ number_format($row['customer_count']) }}</td>
                            <td class="text-right">{{ number_format($row['order_count']) }}</td>
                            <td class="text-right">₩ {{ number_format($row['amount_krw']) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="py-10 text-center text-zinc-500">{{ __('institution_sales.table.empty') }}</td>
                        </tr>
                    @endforelse
                    <tr class="font-bold">
                        <td></td>
                        <td>{{ __('institution_sales.table.total') }}</td>
                        <td class="text-right">{{ number_format($summary['total_customers']) }}</td>
                        <td class="text-right">{{ number_format($summary['total_orders']) }}</td>
                        <td class="text-right">₩ {{ number_format($summary['total_amount_krw']) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</div>
