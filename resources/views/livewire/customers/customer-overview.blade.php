<section
    class="space-y-4"
    data-customer-overview
    wire:poll.300s="refreshOverview"
>
    <div class="flex items-center justify-between gap-3">
        <div>
            <h2 class="text-xl font-semibold tracking-tight text-zinc-900 dark:text-zinc-50">{{ __('customers.overview.title') }}</h2>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ __('customers.overview.description') }}</p>
        </div>
        <span class="text-xs text-zinc-400 dark:text-zinc-500">
            {{ __('customers.overview.updated_at') }} {{ \Carbon\CarbonImmutable::parse($overview['generated_at'])->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s') }}
        </span>
    </div>

    <div class="grid gap-4 md:grid-cols-2">
        <a class="crm-card block transition hover:border-teal-300" href="{{ route('reminders.index') }}" wire:navigate>
            <div class="flex items-center justify-between gap-3">
                <span class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('customers.overview.pending_followups') }}</span>
                <span class="crm-pill tone-amber">{{ __('customers.overview.view_all') }}</span>
            </div>
            <strong class="mt-3 block text-3xl font-semibold text-zinc-900 dark:text-zinc-50">{{ number_format($overview['pending_reminders']) }}</strong>
        </a>
        <a class="crm-card block transition hover:border-teal-300" href="{{ route('reminders.index') }}" wire:navigate>
            <div class="flex items-center justify-between gap-3">
                <span class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('customers.overview.today_tasks') }}</span>
                <span class="crm-pill tone-red">{{ count($overview['today_tasks']) }}</span>
            </div>
            <div class="mt-3 space-y-2">
                @forelse ($overview['today_tasks'] as $task)
                    <div class="flex items-center gap-2 text-sm">
                        <time class="crm-number text-zinc-500">{{ $task['time'] }}</time>
                        <span class="truncate font-medium">{{ $task['customer_name'] }} · {{ $task['title'] }}</span>
                    </div>
                @empty
                    <span class="text-sm text-zinc-500">{{ __('customers.overview.no_tasks') }}</span>
                @endforelse
            </div>
        </a>
    </div>

    <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)]">
        <article class="crm-card">
            <header class="crm-card-header">
                <h3>{{ __('customers.overview.lifecycle') }}</h3>
            </header>
            <div class="grid gap-3 sm:grid-cols-3 lg:grid-cols-1">
                @foreach ($overview['lifecycle'] as $stage)
                    @php
                        $stageLabels = [
                            'booked' => __('customers.overview.stages.booked'),
                            'arrived' => __('customers.overview.stages.arrived'),
                            'treatment_completed' => __('customers.overview.stages.treatment_completed'),
                        ];
                        $stageTones = ['booked' => 'blue', 'arrived' => 'purple', 'treatment_completed' => 'teal'];
                    @endphp
                    <div class="flex items-center gap-3">
                        <span class="crm-pill tone-{{ $stageTones[$stage['key']] ?? 'gray' }}">{{ $stageLabels[$stage['key']] ?? $stage['key'] }}</span>
                        <strong class="crm-number text-lg">{{ number_format($stage['value']) }}</strong>
                        <span class="text-sm text-zinc-500">{{ number_format($stage['percentage'], 1) }}%</span>
                    </div>
                @endforeach
            </div>
        </article>

        <article class="crm-card crm-customer-card">
            <header class="crm-card-header">
                <h3>{{ __('customers.overview.recent_customers') }}</h3>
                <a class="crm-card-link" href="{{ route('customers.index') }}" wire:navigate>{{ __('customers.overview.view_all') }} <span>›</span></a>
            </header>
            <div class="crm-table-wrap">
                <table class="crm-table">
                    <thead>
                        <tr>
                            <th>{{ __('customers.overview.columns.customer') }}</th>
                            <th>{{ __('customers.overview.columns.source') }}</th>
                            <th>{{ __('customers.overview.columns.status') }}</th>
                            <th>{{ __('customers.overview.columns.created_date') }}</th>
                            <th>{{ __('customers.overview.columns.owner') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($overview['recent_customers'] as $customer)
                            <tr wire:key="customer-overview-{{ $customer['id'] }}">
                                <td>
                                    <a class="crm-customer-id crm-number" href="{{ route('customers.show', $customer['id']) }}" wire:navigate>{{ $customer['code'] }}</a>
                                    <div class="text-xs text-zinc-500">{{ $customer['name'] }}</div>
                                </td>
                                <td>{{ $customer['source_name'] }}</td>
                                <td><span class="crm-pill tone-{{ $customer['status_key'] === 'arrived' ? 'purple' : ($customer['status_key'] === 'treatment_completed' ? 'teal' : 'blue') }}">{{ $customer['status_name'] }}</span></td>
                                <td class="crm-number">{{ $customer['created_on'] }}</td>
                                <td>{{ $customer['owner_name'] }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="crm-table-empty">{{ __('customers.overview.no_customers') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </article>
    </div>
</section>
