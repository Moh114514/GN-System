<div>
    <section class="crm-section-header mb-5 flex flex-col items-start justify-between gap-3 sm:flex-row sm:items-start">
        <div class="min-w-0">
            <h2 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-50">{{ __('institution_sales.title') }}</h2>
            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ __('institution_sales.description') }}</p>
        </div>
        <div class="flex shrink-0 flex-wrap gap-2">
            <flux:button wire:click="downloadExport('xlsx')" wire:loading.attr="disabled" wire:target="downloadExport" variant="primary" size="sm">
                {{ __('institution_sales.export.xlsx_button') }}
            </flux:button>
            <flux:button wire:click="downloadExport('pdf')" wire:loading.attr="disabled" wire:target="downloadExport" variant="ghost" size="sm">
                {{ __('institution_sales.export.pdf_button') }}
            </flux:button>
        </div>
    </section>

    <section class="mb-5 rounded-xl border border-zinc-200 bg-white p-4 shadow-none dark:border-zinc-700 dark:bg-zinc-900">
        <div class="grid gap-3 md:grid-cols-2">
            <flux:input wire:model.live="month" type="month" :label="__('institution_sales.fields.month')" />
            <flux:input wire:model.live.debounce.400ms="institutionSearch" :label="__('institution_sales.fields.institution_search')" :placeholder="__('institution_sales.fields.institution_search_placeholder')" />
        </div>
        @error('month')
            <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
        @enderror
    </section>

    <section class="mb-5 grid grid-cols-1 divide-y divide-zinc-200 rounded-xl border border-zinc-200 bg-white shadow-none sm:grid-cols-3 sm:divide-x sm:divide-y-0 dark:divide-zinc-700 dark:border-zinc-700 dark:bg-zinc-900" aria-label="{{ __('institution_sales.table.metrics') }}">
        <div class="px-4 py-3 sm:px-5">
            <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('institution_sales.table.total_customers') }}</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums text-zinc-900 dark:text-zinc-50">{{ number_format($summary['total_customers']) }}</p>
        </div>
        <div class="px-4 py-3 sm:px-5">
            <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('institution_sales.table.total_orders') }}</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums text-zinc-900 dark:text-zinc-50">{{ number_format($summary['total_orders']) }}</p>
        </div>
        <div class="px-4 py-3 sm:px-5">
            <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('institution_sales.table.total_amount') }}</p>
            <p class="mt-1 text-xl font-semibold tabular-nums text-zinc-900 dark:text-zinc-50 sm:text-2xl">₩ {{ number_format($summary['total_amount_krw']) }}</p>
        </div>
    </section>

    <section class="rounded-xl border border-zinc-200 bg-white p-4 shadow-none dark:border-zinc-700 dark:bg-zinc-900 sm:p-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex min-w-0 items-baseline gap-3">
                <h3 class="text-lg font-bold">{{ __('institution_sales.table.title') }}</h3>
                <span class="shrink-0 rounded-full bg-zinc-100 px-2.5 py-1 text-xs font-medium text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">{{ __('institution_sales.table.summary', ['count' => count($summary['rows'])]) }}</span>
            </div>
        </div>

        <div class="crm-table-wrap mt-4">
            <table class="crm-table institution-sales-table min-w-[680px] table-fixed">
                <colgroup>
                    <col style="width: 8%">
                    <col style="width: 44%">
                    <col style="width: 12%">
                    <col style="width: 14%">
                    <col style="width: 22%">
                </colgroup>
                <thead>
                    <tr>
                        <th class="text-center">{{ __('institution_sales.table.number') }}</th>
                        <th>{{ __('institution_sales.table.institution') }}</th>
                        <th class="text-right">{{ __('institution_sales.table.customers') }}</th>
                        <th class="text-right">{{ __('institution_sales.table.orders') }}</th>
                        <th class="text-right">{{ __('institution_sales.table.amount') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($summary['rows'] as $index => $row)
                        <tr wire:key="institution-sales-{{ $row['institution_id'] }}">
                            <td class="text-center tabular-nums">{{ $index + 1 }}</td>
                            <td class="font-semibold">{{ $row['institution'] }}</td>
                            <td class="text-right tabular-nums">{{ number_format($row['customer_count']) }}</td>
                            <td class="text-right tabular-nums">{{ number_format($row['order_count']) }}</td>
                            <td class="text-right tabular-nums">₩ {{ number_format($row['amount_krw']) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="py-10 text-center text-zinc-500">{{ __('institution_sales.table.empty') }}</td>
                        </tr>
                    @endforelse
                    <tr class="bg-zinc-50 dark:bg-zinc-800/60">
                        <td></td>
                        <td class="border-t-2 border-zinc-300 font-semibold dark:border-zinc-600">{{ __('institution_sales.table.total') }}</td>
                        <td class="border-t-2 border-zinc-300 text-right font-semibold tabular-nums dark:border-zinc-600">{{ number_format($summary['total_customers']) }}</td>
                        <td class="border-t-2 border-zinc-300 text-right font-semibold tabular-nums dark:border-zinc-600">{{ number_format($summary['total_orders']) }}</td>
                        <td class="border-t-2 border-zinc-300 text-right font-semibold tabular-nums dark:border-zinc-600">₩ {{ number_format($summary['total_amount_krw']) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</div>
