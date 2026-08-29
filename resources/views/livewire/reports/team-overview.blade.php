<div>
    @php
        $overview = $snapshot['overview'];
        $selectedGroup = $snapshot['selected_group'];
        $groups = $snapshot['groups'];
        $metricDefinitions = [
            ['customer_service', __('team.metrics.customer_service'), 'users', 'teal'],
            ['customers', __('team.metrics.customers'), 'user-group', 'blue'],
            ['agents', __('team.metrics.agents'), 'building-office', 'purple'],
            ['pending_reminders', __('team.metrics.pending_reminders'), 'bell-alert', 'amber'],
            ['overdue_reminders', __('team.metrics.overdue_reminders'), 'exclamation-triangle', 'red'],
            ['amount_krw', __('team.metrics.monthly_sales'), 'banknotes', 'teal'],
        ];
    @endphp

    <section class="crm-section-header mb-6">
        <div>
            <h2 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-50">{{ __('team.title') }}</h2>
            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ __('team.description') }}</p>
        </div>
        @if ($snapshot['is_global'] || count($groups) > 1)
            <div class="w-full sm:w-72">
                <flux:select wire:model.live="groupId" :label="__('team.group_selector')">
                    <flux:select.option value="">{{ __('team.all_groups') }}</flux:select.option>
                    @foreach ($groups as $group)
                        <flux:select.option value="{{ $group['id'] }}">{{ $group['name'] }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
        @endif
    </section>

    <section class="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-3" aria-label="{{ __('team.metrics.heading') }}">
        @foreach ($metricDefinitions as [$key, $label, $icon, $tone])
            <article class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex items-center gap-3">
                    <span class="crm-metric-icon tone-{{ $tone }}"><flux:icon :name="$icon" /></span>
                    <span class="text-sm text-zinc-500 dark:text-zinc-400">{{ $label }}</span>
                </div>
                <strong class="mt-3 block text-2xl font-bold text-zinc-900 dark:text-zinc-50">
                    @if ($key === 'amount_krw')
                        ₩ {{ number_format($overview[$key]) }}
                    @else
                        {{ number_format($overview[$key]) }}
                    @endif
                </strong>
            </article>
        @endforeach
    </section>

    @if ($selectedGroup)
        <section class="mb-6 rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-teal-600">{{ __('team.group_detail') }}</p>
                    <h3 class="mt-1 text-xl font-bold text-zinc-900 dark:text-zinc-50">{{ $selectedGroup['name'] }}</h3>
                    <p class="mt-1 text-sm text-zinc-500">{{ $selectedGroup['code'] }} · {{ __('team.bd') }}：{{ $selectedGroup['bd_name'] }}</p>
                </div>
                <div class="text-sm text-zinc-500">{{ __('team.generated_at') }}：{{ $snapshot['generated_at'] }}</div>
            </div>

            <div class="mt-5 grid gap-3 sm:grid-cols-3">
                <a href="{{ route('reminders.index') }}" wire:navigate class="rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-900 dark:bg-amber-950/20">
                    <span class="text-sm text-amber-800 dark:text-amber-200">{{ __('team.attention.overdue') }}</span>
                    <strong class="mt-1 block text-2xl text-amber-900 dark:text-amber-100">{{ number_format($selectedGroup['overdue_reminders']) }}</strong>
                </a>
                <a href="{{ route('customers.index') }}" wire:navigate class="rounded-xl border border-red-200 bg-red-50 p-4 dark:border-red-900 dark:bg-red-950/20">
                    <span class="text-sm text-red-800 dark:text-red-200">{{ __('team.attention.unassigned') }}</span>
                    <strong class="mt-1 block text-2xl text-red-900 dark:text-red-100">{{ number_format($selectedGroup['unassigned_customers']) }}</strong>
                </a>
                <a href="{{ route('customers.index') }}" wire:navigate class="rounded-xl border border-blue-200 bg-blue-50 p-4 dark:border-blue-900 dark:bg-blue-950/20">
                    <span class="text-sm text-blue-800 dark:text-blue-200">{{ __('team.attention.pending_transfers') }}</span>
                    <strong class="mt-1 block text-2xl text-blue-900 dark:text-blue-100">{{ number_format($selectedGroup['pending_transfer_requests']) }}</strong>
                </a>
            </div>

            <div class="mt-6 overflow-x-auto">
                <h4 class="mb-3 text-base font-semibold">{{ __('team.workload') }}</h4>
                <table class="crm-table min-w-[760px]">
                    <thead>
                        <tr>
                            <th>{{ __('team.owner') }}</th>
                            <th>{{ __('team.customers') }}</th>
                            <th>{{ __('team.pending_followups') }}</th>
                            <th>{{ __('team.overdue') }}</th>
                            <th>{{ __('team.new_customers') }}</th>
                            <th>{{ __('team.monthly_sales') }}</th>
                            <th>{{ __('team.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($selectedGroup['owners'] as $owner)
                            <tr wire:key="team-owner-{{ $owner['id'] }}">
                                <td class="font-semibold">{{ $owner['name'] }}</td>
                                <td>{{ number_format($owner['customers']) }}</td>
                                <td>{{ number_format($owner['pending_reminders']) }}</td>
                                <td><span class="crm-pill {{ $owner['overdue_reminders'] > 0 ? 'tone-red' : 'tone-blue' }}">{{ number_format($owner['overdue_reminders']) }}</span></td>
                                <td>{{ number_format($owner['new_customers']) }}</td>
                                <td>₩ {{ number_format($owner['amount_krw']) }}</td>
                                <td><a class="font-semibold text-teal-700 hover:underline" href="{{ route('customers.index', ['ownerId' => $owner['id']]) }}" wire:navigate>{{ __('team.view_customers') }} →</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="py-8 text-center text-zinc-500">{{ __('team.empty_owners') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-6 overflow-x-auto">
                <h4 class="mb-3 text-base font-semibold">{{ __('team.lifecycle') }}</h4>
                <table class="crm-table min-w-[620px]">
                    <thead><tr><th>{{ __('team.owner') }}</th><th>{{ __('team.status.booked') }}</th><th>{{ __('team.status.arrived') }}</th><th>{{ __('team.status.treated') }}</th></tr></thead>
                    <tbody>
                        @foreach ($selectedGroup['owners'] as $owner)
                            <tr wire:key="team-lifecycle-{{ $owner['id'] }}"><td class="font-semibold">{{ $owner['name'] }}</td><td>{{ number_format($owner['booked']) }}</td><td>{{ number_format($owner['arrived']) }}</td><td>{{ number_format($owner['treatment_completed']) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    @if (! $selectedGroup || $snapshot['is_global'])
        <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h3 class="text-lg font-bold">{{ $snapshot['is_global'] ? __('team.groups_overview') : __('team.team_overview') }}</h3>
                    <p class="mt-1 text-sm text-zinc-500">{{ __('team.groups_description') }}</p>
                </div>
            </div>
            <div class="mt-5 overflow-x-auto">
                <table class="crm-table min-w-[820px]">
                    <thead><tr><th>{{ __('team.group') }}</th><th>{{ __('team.bd') }}</th><th>{{ __('team.customer_service') }}</th><th>{{ __('team.agents') }}</th><th>{{ __('team.customers') }}</th><th>{{ __('team.pending_followups') }}</th><th>{{ __('team.overdue') }}</th><th>{{ __('team.status_label') }}</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($groups as $group)
                            <tr wire:key="team-group-{{ $group['id'] }}">
                                <td class="font-semibold">{{ $group['name'] }}<div class="text-xs font-normal text-zinc-500">{{ $group['code'] }}</div></td>
                                <td>{{ $group['bd_name'] }}</td>
                                <td>{{ number_format($group['customer_service_count']) }}</td>
                                <td>{{ number_format($group['agent_count']) }}</td>
                                <td>{{ number_format($group['customer_count']) }}</td>
                                <td>{{ number_format($group['pending_reminders']) }}</td>
                                <td><span class="crm-pill {{ $group['overdue_reminders'] > 0 ? 'tone-red' : 'tone-blue' }}">{{ number_format($group['overdue_reminders']) }}</span></td>
                                <td>{{ $group['overdue_reminders'] > 0 || $group['unassigned_customers'] > 0 ? __('team.needs_attention') : __('team.normal') }}</td>
                                <td><a class="font-semibold text-teal-700 hover:underline" href="{{ route('team-overview.index', ['groupId' => $group['id']]) }}" wire:navigate>{{ __('team.view_group') }} →</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="py-8 text-center text-zinc-500">{{ __('team.empty_groups') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    @endif
</div>
