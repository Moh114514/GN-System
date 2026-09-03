<div class="mx-auto max-w-6xl space-y-6">
    <x-page-back :href="route('customers.show', $customerId)" :label="__('orders.customer.back')" />

    <section class="crm-section-header">
        <div>
            <p class="text-xs font-medium text-zinc-400">{{ __('orders.customer.eyebrow', ['code' => $context['customer']['code']]) }}</p>
            <h2 class="mt-1 text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-50">{{ $context['customer']['name'] }}{{ __('orders.customer.heading_suffix') }}</h2>
            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ __('orders.customer.description') }}</p>
        </div>
        <flux:button href="{{ route('institution-returns.index') }}" wire:navigate variant="primary" size="sm">{{ __('orders.institution_return.title') }}</flux:button>
    </section>

    <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex items-start justify-between gap-4">
            <div><h3 class="font-semibold">{{ __('orders.customer.records') }}</h3><p class="mt-1 text-sm text-zinc-500">{{ __('orders.customer.records_description') }}</p></div>
        </div>
        <div class="crm-table-wrap mt-4"><table class="crm-table">
            <thead><tr><th>{{ __('orders.fields.order') }}</th><th>{{ __('orders.fields.status_label') }}</th><th>{{ __('orders.fields.promotion_fee') }}</th><th>{{ __('orders.fields.occurred_on') }}</th></tr></thead>
            <tbody>
            @forelse ($context['orders'] as $order)
                <tr wire:key="order-{{ $order['id'] }}">
                    <td><a class="font-semibold text-teal-700 hover:underline" href="{{ route('orders.show', $order['id']) }}" wire:navigate>{{ $order['projectName'] }}</a><div class="text-xs text-zinc-500">₩ {{ number_format($order['amountKrw']) }}</div></td>
                    <td>{{ ['pending' => __('orders.statuses.pending'), 'completed' => __('orders.statuses.completed'), 'cancelled' => __('orders.statuses.cancelled')][$order['status']] ?? $order['status'] }}</td>
                    <td>{{ $order['commissionAmountKrw'] === null ? __('orders.values.empty') : '₩ '.number_format($order['commissionAmountKrw']).' · '.number_format($order['commissionRateBps'] / 100, 2).'%' }}</td>
                    <td>{{ $order['occurredOn'] ?? $order['completedOn'] ?? __('orders.values.empty') }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="py-10 text-center text-zinc-500">{{ __('orders.customer.no_orders') }}</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </section>
</div>
