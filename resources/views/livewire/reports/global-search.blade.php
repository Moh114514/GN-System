<div>
    <x-page-back :href="route('dashboard')" :label="__('search.back')" class="mb-4" />
    <section class="crm-section-header">
        <div>
            <h2 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-50">{{ __('search.title') }}</h2>
            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">
                @if ($query === '')
                    {{ __('search.description.empty') }}
                @else
                    {{ __('search.description.results', ['query' => $query]) }}
                @endif
            </p>
        </div>
    </section>

    @if ($query === '')
        <section class="crm-card">
            <div class="crm-panel-empty">
                <flux:icon.magnifying-glass />
                {{ __('search.empty.prompt') }}
            </div>
        </section>
    @else
        @php
            $agentResults = $results['agents'];
            $total = $results['customers']['total']
                + $results['orders']['total']
                + ($agentResults['total'] ?? 0);
        @endphp

        <p class="mb-4 text-sm text-zinc-500">{{ __('search.summary', ['total' => $total]) }}</p>

        <div class="grid gap-4">
            <section class="crm-card">
                <header class="crm-card-header">
                    <h2>{{ __('search.groups.customers.title') }} <span class="text-zinc-400">({{ $results['customers']['total'] }})</span></h2>
                    <a class="crm-card-link" href="{{ route('customers.index', ['search' => $query]) }}" wire:navigate>
                        {{ __('search.groups.customers.view_all') }} <span>›</span>
                    </a>
                </header>
                <div class="crm-table-wrap">
                    <table class="crm-table" aria-label="{{ __('search.aria.customers_table') }}">
                        <thead><tr><th>{{ __('search.groups.customers.columns.name') }}</th><th>{{ __('search.groups.customers.columns.status') }}</th><th>{{ __('search.groups.customers.columns.actions') }}</th></tr></thead>
                        <tbody>
                            @forelse ($results['customers']['items'] as $customer)
                                <tr>
                                    <td>
                                        <a class="font-semibold text-teal-700 hover:underline" href="{{ route('customers.show', $customer['id']) }}" wire:navigate>{{ $customer['name'] }}</a>
                                        <div class="text-xs text-zinc-500">{{ $customer['code'] }}</div>
                                    </td>
                                    <td>{{ $customer['status'] }}</td>
                                    <td class="text-right"><a class="crm-card-link" href="{{ route('customers.show', $customer['id']) }}" wire:navigate>{{ __('search.groups.customers.view_profile') }} <span>›</span></a></td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="crm-table-empty">{{ __('search.groups.customers.empty') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="crm-card">
                <header class="crm-card-header">
                    <h2>{{ __('search.groups.orders.title') }} <span class="text-zinc-400">({{ $results['orders']['total'] }})</span></h2>
                    <a class="crm-card-link" href="{{ route('reports.search', ['projectName' => $query]) }}" wire:navigate>
                        {{ __('search.groups.orders.view_all') }} <span>›</span>
                    </a>
                </header>
                <div class="crm-table-wrap">
                    <table class="crm-table" aria-label="{{ __('search.aria.orders_table') }}">
                        <thead><tr><th>{{ __('search.groups.orders.columns.project') }}</th><th>{{ __('search.groups.orders.columns.customer') }}</th><th>{{ __('search.groups.orders.columns.agent') }}</th><th>{{ __('search.groups.orders.columns.amount') }}</th><th>{{ __('search.groups.orders.columns.completed_at') }}</th></tr></thead>
                        <tbody>
                            @forelse ($results['orders']['items'] as $order)
                                <tr>
                                    <td>
                                        <a class="font-semibold text-teal-700 hover:underline" href="{{ route('customers.orders', $order['customer_id']) }}" wire:navigate>{{ $order['project'] }}</a>
                                    </td>
                                    <td><a class="hover:underline" href="{{ route('customers.show', $order['customer_id']) }}" wire:navigate>{{ $order['customer'] }}</a></td>
                                    <td>{{ $order['agent'] }}</td>
                                    <td>₩{{ number_format((int) $order['amount_krw']) }}</td>
                                    <td>{{ $order['completed_at'] }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="crm-table-empty">{{ __('search.groups.orders.empty') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            @if ($agentResults !== null)
                <section class="crm-card">
                    <header class="crm-card-header">
                        <h2>{{ __('search.groups.agents.title') }} <span class="text-zinc-400">({{ $agentResults['total'] }})</span></h2>
                        <a class="crm-card-link" href="{{ route('agents.index', ['search' => $query]) }}" wire:navigate>
                            {{ __('search.groups.agents.view_all') }} <span>›</span>
                        </a>
                    </header>
                    <div class="crm-table-wrap">
                        <table class="crm-table" aria-label="{{ __('search.aria.agents_table') }}">
                            <thead><tr><th>{{ __('search.groups.agents.columns.name') }}</th><th>{{ __('search.groups.agents.columns.status') }}</th><th>{{ __('search.groups.agents.columns.actions') }}</th></tr></thead>
                            <tbody>
                                @forelse ($agentResults['items'] as $agent)
                                    <tr>
                                        <td>
                                            <a class="font-semibold text-teal-700 hover:underline" href="{{ route('agents.show', $agent['id']) }}" wire:navigate>{{ $agent['name'] }}</a>
                                            <div class="text-xs text-zinc-500">{{ $agent['code'] }}</div>
                                        </td>
                                        @php($agentStatusLabel = __('search.statuses.'.$agent['status']))
                                        <td>{{ $agentStatusLabel === 'search.statuses.'.$agent['status'] ? $agent['status'] : $agentStatusLabel }}</td>
                                        <td class="text-right"><a class="crm-card-link" href="{{ route('agents.show', $agent['id']) }}" wire:navigate>{{ __('search.groups.agents.view_profile') }} <span>›</span></a></td>
                                    </tr>
                                @empty
                                    <tr><td colspan="3" class="crm-table-empty">{{ __('search.groups.agents.empty') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </section>
            @endif

            @if ($total === 0)
                <p class="py-2 text-center text-sm text-zinc-500">{{ __('search.empty.all', ['query' => $query]) }}</p>
            @endif
        </div>
    @endif
</div>
