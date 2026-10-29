<div>
    <x-page-back :href="route('direct-customers.index')" :label="__('settlements.direct_commission.back')" class="mb-4" />
    <section class="crm-section-header mb-6">
        <div>
            <h2 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-50">{{ __('settlements.direct_commission.title') }}</h2>
            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ __('settlements.direct_commission.description') }}</p>
        </div>
        <div class="flex flex-wrap gap-3">
            <div class="w-44"><flux:input type="month" wire:model.live="month" :label="__('settlements.direct_commission.month')" /></div>
            @if ($ownerOptions !== [])
                <div class="w-56">
                    <flux:select wire:model.live="ownerId" :label="__('settlements.direct_commission.owner')">
                        <flux:select.option value="">{{ __('settlements.direct_commission.all_owners') }}</flux:select.option>
                        @foreach ($ownerOptions as $owner)
                            <flux:select.option value="{{ $owner['id'] }}">{{ $owner['name'] }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
            @endif
        </div>
    </section>

    <section class="mb-6 grid gap-3 sm:grid-cols-3" aria-label="{{ __('settlements.direct_commission.summary') }}">
        <article class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900"><span class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('settlements.direct_commission.orders') }}</span><strong class="mt-3 block text-2xl font-bold">{{ number_format($totals['orders']) }}</strong></article>
        <article class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900"><span class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('settlements.direct_commission.order_amount') }}</span><strong class="mt-3 block text-2xl font-bold">₩ {{ number_format($totals['order_amount_krw']) }}</strong></article>
        <article class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900"><span class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('settlements.direct_commission.commission') }}</span><strong class="mt-3 block text-2xl font-bold">₩ {{ number_format($totals['commission_amount_krw']) }}</strong></article>
    </section>

    <section class="mb-6 rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <h3 class="text-lg font-bold text-zinc-900 dark:text-zinc-50">{{ __('settlements.direct_commission.monthly_performance') }}</h3>
        <div class="mt-4 overflow-x-auto">
            <table class="crm-table min-w-[720px]">
                <thead><tr><th>{{ __('settlements.direct_commission.owner') }}</th><th>{{ __('settlements.direct_commission.orders') }}</th><th>{{ __('settlements.direct_commission.order_amount') }}</th><th>{{ __('settlements.direct_commission.rate') }}</th><th>{{ __('settlements.direct_commission.commission') }}</th></tr></thead>
                <tbody>
                    @forelse ($summaries as $summary)
                        @php($rates = array_keys($summary['rates']))
                        <tr wire:key="direct-commission-summary-{{ $summary['owner_id'] }}">
                            <td class="font-semibold">{{ $summary['owner'] }}</td><td>{{ number_format($summary['orders']) }}</td><td>₩ {{ number_format($summary['order_amount_krw']) }}</td>
                            <td>{{ count($rates) > 1 ? __('settlements.direct_commission.multiple_rates') : (count($rates) === 1 ? number_format($rates[0] / 100, 2).'%' : '—') }}</td>
                            <td>₩ {{ number_format($summary['commission_amount_krw']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-8 text-center text-zinc-500">{{ __('settlements.direct_commission.empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <h3 class="mb-4 text-lg font-bold text-zinc-900 dark:text-zinc-50">{{ __('settlements.direct_commission.details') }}</h3>
        <div class="crm-table-wrap">
            <table class="crm-table">
                <thead><tr><th>{{ __('settlements.direct_commission.owner') }}</th><th>{{ __('settlements.direct_commission.order_id') }}</th><th>{{ __('settlements.direct_commission.order_amount') }}</th><th>{{ __('settlements.direct_commission.rate') }}</th><th>{{ __('settlements.direct_commission.commission') }}</th><th>{{ __('settlements.direct_commission.channel') }}</th><th>{{ __('settlements.direct_commission.completed_at') }}</th><th>{{ __('settlements.direct_commission.status') }}</th></tr></thead>
                <tbody>
                    @forelse ($records as $record)
                        @php($ownerSummary = collect($summaries)->firstWhere('owner_id', $record['owner_id']))
                        <tr wire:key="direct-commission-record-{{ $record['id'] }}">
                            <td>{{ $ownerSummary['owner'] ?? __('settlements.direct_commission.unknown_owner') }}</td>
                            <td>#{{ $record['order_id'] }}</td><td>{{ number_format($record['order_amount_krw']) }}</td><td>{{ number_format($record['rate_bps'] / 100, 2) }}%</td><td>{{ number_format($record['commission_amount_krw']) }}</td><td>{{ $record['direct_channel'] ?: '—' }}</td><td>{{ $record['completed_at'] }}</td>
                            <td>{{ $record['status'] === 'active' ? __('settlements.direct_commission.active') : __('settlements.direct_commission.voided') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="py-8 text-center text-zinc-500">{{ __('settlements.direct_commission.empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
