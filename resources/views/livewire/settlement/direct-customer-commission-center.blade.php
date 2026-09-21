<div>
    <x-page-back :href="route('dashboard')" :label="__('settlements.direct_commission.back')" class="mb-4" />
    <section class="crm-section-header">
        <div>
            <p class="text-xs font-medium text-zinc-400">{{ __('settlements.direct_commission.title') }}</p>
            <h2 class="mt-1 text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-50">{{ __('settlements.direct_commission.title') }}</h2>
            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ __('settlements.direct_commission.description') }}</p>
        </div>
    </section>

    <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <div class="crm-table-wrap">
            <table class="crm-table">
                <thead><tr><th>{{ __('settlements.direct_commission.order_amount') }}</th><th>{{ __('settlements.direct_commission.rate') }}</th><th>{{ __('settlements.direct_commission.commission') }}</th><th>{{ __('settlements.direct_commission.channel') }}</th><th>{{ __('settlements.direct_commission.completed_at') }}</th><th>{{ __('settlements.direct_commission.status') }}</th></tr></thead>
                <tbody>
                    @forelse ($records as $record)
                        <tr>
                            <td>{{ number_format($record['order_amount_krw']) }}</td>
                            <td>{{ number_format($record['rate_bps'] / 100, 2) }}%</td>
                            <td>{{ number_format($record['commission_amount_krw']) }}</td>
                            <td>{{ $record['direct_channel'] ?: '—' }}</td>
                            <td>{{ $record['completed_at'] }}</td>
                            <td>{{ $record['status'] === 'active' ? __('settlements.direct_commission.active') : __('settlements.direct_commission.voided') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6">{{ __('settlements.direct_commission.empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
