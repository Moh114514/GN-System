<div>
    <x-page-back :href="route('reports.institution-sales', ['month' => $detail['month']])" :label="__('institution_sales.detail.back')" class="mb-4" />

    <section class="crm-section-header mb-5 flex flex-col items-start justify-between gap-3 sm:flex-row sm:items-start">
        <div class="min-w-0">
            <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('institution_sales.detail.breadcrumb') }}</p>
            <h2 class="mt-1 text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-50">{{ $detail['institution'] }}</h2>
            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ __('institution_sales.period', ['month' => $detail['month']]) }}</p>
        </div>
    </section>

    <section class="mb-5 grid grid-cols-1 divide-y divide-zinc-200 rounded-xl border border-zinc-200 bg-white shadow-none sm:grid-cols-4 sm:divide-x sm:divide-y-0 dark:divide-zinc-700 dark:border-zinc-700 dark:bg-zinc-900" aria-label="{{ __('institution_sales.detail.metrics') }}">
        <div class="px-4 py-3 sm:px-5"><p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('institution_sales.table.total_customers') }}</p><p class="mt-1 text-2xl font-semibold tabular-nums">{{ number_format($detail['customer_count']) }}</p></div>
        <div class="px-4 py-3 sm:px-5"><p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('institution_sales.table.total_orders') }}</p><p class="mt-1 text-2xl font-semibold tabular-nums">{{ number_format($detail['order_count']) }}</p></div>
        <div class="px-4 py-3 sm:px-5"><p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('institution_sales.detail.agent_count') }}</p><p class="mt-1 text-2xl font-semibold tabular-nums">{{ number_format($detail['agent_count']) }}</p></div>
        <div class="px-4 py-3 sm:px-5"><p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('institution_sales.table.total_amount') }}</p><p class="mt-1 text-xl font-semibold tabular-nums sm:text-2xl">₩ {{ number_format($detail['amount_krw']) }}</p></div>
    </section>

    <section class="mb-5 rounded-xl border border-zinc-200 bg-white p-4 shadow-none dark:border-zinc-700 dark:bg-zinc-900 sm:p-5">
        <h3 class="text-lg font-bold">{{ __('institution_sales.detail.agent_breakdown') }}</h3>
        <div class="crm-table-wrap mt-4">
            <table class="crm-table min-w-[680px]">
                <thead><tr><th>{{ __('institution_sales.detail.agent') }}</th><th class="text-right">{{ __('institution_sales.table.customers') }}</th><th class="text-right">{{ __('institution_sales.table.orders') }}</th><th class="text-right">{{ __('institution_sales.table.amount') }}</th><th class="text-right">{{ __('institution_sales.detail.share') }}</th></tr></thead>
                <tbody>
                    @forelse ($detail['agents'] as $agent)
                        <tr wire:key="institution-sales-agent-{{ $agent['id'] ?? 'unassigned' }}">
                            <td>
                                @if ($agent['id'] !== null)
                                    <a class="font-semibold text-teal-700 hover:underline" href="{{ route('agents.show', $agent['id']) }}" wire:navigate>{{ $agent['name'] }}</a>
                                @else
                                    <span class="font-semibold">{{ $agent['name'] }}</span>
                                @endif
                            </td>
                            <td class="text-right tabular-nums">{{ number_format($agent['customer_count']) }}</td>
                            <td class="text-right tabular-nums">{{ number_format($agent['order_count']) }}</td>
                            <td class="text-right tabular-nums">₩ {{ number_format($agent['amount_krw']) }}</td>
                            <td class="text-right tabular-nums">{{ number_format($agent['share'], 2) }}%</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-10 text-center text-zinc-500">{{ __('institution_sales.detail.no_agents') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="rounded-xl border border-zinc-200 bg-white p-4 shadow-none dark:border-zinc-700 dark:bg-zinc-900 sm:p-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h3 class="text-lg font-bold">{{ __('institution_sales.detail.order_details') }}</h3>
            <span class="text-sm text-zinc-500">{{ __('institution_sales.detail.order_summary', ['total' => number_format($detail['orders_total'])]) }}</span>
        </div>
        <div class="mt-4 grid gap-3 md:grid-cols-3">
            <flux:select wire:model.live="agentId" :label="__('institution_sales.detail.agent_filter')">
                <flux:select.option value="">{{ __('institution_sales.detail.all_agents') }}</flux:select.option>
                @foreach ($agentOptions as $agent)
                    <flux:select.option value="{{ $agent['id'] }}">{{ $agent['name'] }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input wire:model.live.debounce.400ms="search" :label="__('institution_sales.detail.search')" />
            <flux:select wire:model.live="sort" :label="__('institution_sales.detail.sort')">
                <flux:select.option value="occurred_on">{{ __('institution_sales.detail.sort_date') }}</flux:select.option>
                <flux:select.option value="amount">{{ __('institution_sales.detail.sort_amount') }}</flux:select.option>
                <flux:select.option value="project">{{ __('institution_sales.detail.sort_project') }}</flux:select.option>
            </flux:select>
        </div>
        <div class="crm-table-wrap mt-4">
            <table class="crm-table min-w-[760px]">
                <thead><tr><th>{{ __('institution_sales.detail.date') }}</th><th>{{ __('institution_sales.detail.order') }}</th><th>{{ __('institution_sales.detail.customer') }}</th><th>{{ __('institution_sales.detail.agent') }}</th><th>{{ __('institution_sales.detail.project') }}</th><th class="text-right">{{ __('institution_sales.table.amount') }}</th></tr></thead>
                <tbody>
                    @forelse ($detail['orders'] as $order)
                        <tr wire:key="institution-sales-order-{{ $order['id'] }}">
                            <td class="tabular-nums">{{ $order['occurred_on'] }}</td>
                            <td><a class="font-semibold text-teal-700 hover:underline" href="{{ route('orders.show', $order['id']) }}" wire:navigate>#{{ $order['id'] }}</a></td>
                            <td><a class="font-semibold text-teal-700 hover:underline" href="{{ route('customers.show', $order['customer_id']) }}" wire:navigate>{{ $order['customer'] }}</a></td>
                            <td>{{ $order['agent'] }}</td>
                            <td>{{ $order['project_name'] }}</td>
                            <td class="text-right tabular-nums">₩ {{ number_format($order['amount_krw']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-10 text-center text-zinc-500">{{ __('institution_sales.detail.no_orders') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4 flex justify-end gap-2">
            <flux:button wire:click="previousPage" variant="ghost" :disabled="$detail['orders_current_page'] <= 1">{{ __('institution_sales.detail.previous') }}</flux:button>
            <span class="self-center text-sm text-zinc-500">{{ __('institution_sales.detail.page', ['current' => $detail['orders_current_page'], 'last' => $detail['orders_last_page']]) }}</span>
            <flux:button wire:click="nextPage" variant="ghost" :disabled="$detail['orders_current_page'] >= $detail['orders_last_page']">{{ __('institution_sales.detail.next') }}</flux:button>
        </div>
    </section>
</div>
