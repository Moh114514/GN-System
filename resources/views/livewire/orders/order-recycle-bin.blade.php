<div>
    <x-page-back :href="route('orders.index')" :label="__('orders.recycle.back')" class="mb-4" />

    <section class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h2 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-50">{{ __('orders.recycle_bin_title') }}</h2>
            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ __('orders.recycle.description') }}</p>
        </div>
    </section>

    <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        @php
            $selectedInstitution = collect($options['institutions'])->firstWhere('id', (int) $institutionFilter);
            $selectedAgent = collect($options['agents'])->firstWhere('id', (int) $agentFilter);
            $hasFilters = $search !== '' || $statusFilter !== '' || $institutionFilter !== '' || $agentFilter !== '' || $perPage !== 20;
        @endphp
        <div class="flex flex-wrap items-center gap-2">
            <flux:input class="mr-1 w-full sm:w-72" wire:model.live.debounce.350ms="search" icon="magnifying-glass" :placeholder="__('orders.center.search')" size="sm" />
            <flux:select class="w-32" wire:model.live="statusFilter" size="sm" :aria-label="__('orders.fields.status_filter')">
                <flux:select.option value="">{{ __('orders.fields.all_statuses') }}</flux:select.option>
                <flux:select.option value="cancelled">{{ __('orders.statuses.cancelled') }}</flux:select.option>
            </flux:select>
            <flux:dropdown>
                <flux:button class="rounded-full bg-zinc-100 dark:bg-zinc-800" variant="ghost" size="sm" icon:trailing="chevron-down">{{ $selectedInstitution['name'] ?? __('orders.center.all_institutions') }}</flux:button>
                <flux:menu class="max-h-72 overflow-y-auto">
                    <flux:menu.item wire:click="$set('institutionFilter', '')">{{ __('orders.center.all_institutions') }}</flux:menu.item>
                    @foreach ($options['institutions'] as $institution)
                        <flux:menu.item wire:click="$set('institutionFilter', '{{ $institution['id'] }}')">{{ $institution['name'] }}</flux:menu.item>
                    @endforeach
                </flux:menu>
            </flux:dropdown>
            <flux:dropdown>
                <flux:button class="rounded-full bg-zinc-100 dark:bg-zinc-800" variant="ghost" size="sm" icon:trailing="chevron-down">{{ $selectedAgent['name'] ?? __('orders.center.all_agents') }}</flux:button>
                <flux:menu class="max-h-72 overflow-y-auto">
                    <flux:menu.item wire:click="$set('agentFilter', '')">{{ __('orders.center.all_agents') }}</flux:menu.item>
                    @foreach ($options['agents'] as $agent)
                        <flux:menu.item wire:click="$set('agentFilter', '{{ $agent['id'] }}')">{{ $agent['name'] }}</flux:menu.item>
                    @endforeach
                </flux:menu>
            </flux:dropdown>
            <flux:dropdown>
                <flux:button class="rounded-full bg-zinc-100 dark:bg-zinc-800" variant="ghost" size="sm" icon:trailing="chevron-down">{{ __('orders.fields.per_page', ['count' => $perPage]) }}</flux:button>
                <flux:menu>
                    @foreach ([20, 50, 100] as $size)
                        <flux:menu.item wire:click="$set('perPage', {{ $size }})">{{ __('orders.fields.per_page', ['count' => $size]) }}</flux:menu.item>
                    @endforeach
                </flux:menu>
            </flux:dropdown>
            @if ($hasFilters)
                <flux:button wire:click="clearFilters" variant="ghost" size="sm" icon="x-mark">{{ __('orders.center.clear') }}</flux:button>
            @endif
        </div>

        <div class="crm-table-wrap mt-5">
            <table class="crm-table">
                <thead><tr><th>{{ __('orders.fields.order') }}</th><th>{{ __('orders.fields.customer') }}</th><th>{{ __('orders.fields.institution') }}</th><th>{{ __('orders.fields.transaction_amount') }}</th><th>{{ __('orders.fields.status_label') }}</th><th>{{ __('orders.fields.time') }}</th><th></th></tr></thead>
                <tbody>
                    @forelse ($orders as $order)
                        <tr wire:key="recycle-order-{{ $order['id'] }}">
                            <td><a class="font-semibold text-teal-700 hover:underline" href="{{ route('orders.show', $order['id']) }}" wire:navigate>{{ $order['project_name'] }}</a><div class="text-xs text-zinc-500">#{{ $order['id'] }}</div></td>
                            <td><a class="font-semibold text-teal-700 hover:underline" href="{{ route('customers.show', $order['customer_id']) }}" wire:navigate>{{ $order['customer_name'] }}</a><div class="text-xs text-zinc-500">{{ $order['customer_code'] }}</div></td>
                            <td>{{ $order['institution'] }}<div class="text-xs text-zinc-500">{{ __('orders.sources.agent') }} · {{ $order['source'] }}</div></td>
                            <td>₩ {{ number_format($order['amount_krw']) }}</td>
                            <td><span class="crm-pill tone-red">{{ __('orders.statuses.cancelled') }}</span></td>
                            <td>{{ $order['completed_at'] ?? $order['created_at'] }}<div class="text-xs text-zinc-500">{{ $order['completed_at'] ? __('orders.fields.completed_time') : __('orders.fields.created_at') }}</div></td>
                            <td><flux:button href="{{ route('orders.show', $order['id']) }}" wire:navigate variant="ghost" size="sm">{{ __('orders.center.view_details') }}</flux:button></td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-10 text-center text-zinc-500">{{ __('orders.recycle.no_orders') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-5">{{ $orders->links() }}</div>
    </section>
</div>
