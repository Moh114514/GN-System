<div>
    <section class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <p class="text-xs font-medium text-zinc-400">{{ __('customers.direct.title.list') }}</p>
            <h2 class="mt-1 text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-50">{{ __('customers.direct.list.heading') }}</h2>
            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ __('customers.direct.list.description') }}</p>
        </div>
        @if (! auth()->user()->isOperationsDirector())
            <flux:button :href="route('direct-customers.create')" variant="primary" size="sm" icon="plus" wire:navigate>{{ __('customers.direct.list.create') }}</flux:button>
        @endif
    </section>

    <section class="mb-5 grid gap-3 sm:grid-cols-3" aria-label="{{ __('customers.direct.list.followup_stats') }}">
        @foreach (['today', 'overdue', 'upcoming'] as $followupMetric)
            <button type="button" wire:click="$set('followupStatus', '{{ $followupMetric }}')" class="rounded-xl border p-4 text-left shadow-sm transition hover:border-teal-400 {{ $followupStatus === $followupMetric ? 'border-teal-500 bg-teal-50 dark:bg-teal-950/30' : 'border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900' }}">
                <span class="text-sm text-zinc-500">{{ __('customers.direct.list.followup_'.$followupMetric) }}</span>
                <strong data-test="followup-count-{{ $followupMetric }}" class="mt-1 block text-2xl {{ $followupMetric === 'overdue' ? 'text-red-600' : 'text-zinc-900 dark:text-zinc-50' }}">{{ $followupStats[$followupMetric] }}</strong>
            </button>
        @endforeach
    </section>

    <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        @php
            $selectedStatus = collect($options['statuses'] ?? [])->firstWhere('id', (int) $statusId);
            $selectedOwner = collect($ownerCandidates)->firstWhere('id', (int) $ownerId);
            $hasFilters = $search !== '' || $statusId !== '' || $ownerId !== '' || $createdFrom !== '' || $createdTo !== '' || $followupStatus !== '' || $perPage !== 20;
        @endphp
        <div class="space-y-4">
            <div class="flex flex-wrap items-center gap-2">
                <flux:input class="w-full sm:w-72" wire:model.live.debounce.350ms="search" icon="magnifying-glass" :placeholder="__('customers.direct.list.search_placeholder')" size="sm" />
                <flux:dropdown>
                    <flux:button class="w-32 rounded-full bg-zinc-100 dark:bg-zinc-800" variant="ghost" size="sm" icon:trailing="chevron-down">{{ $selectedStatus['name'] ?? __('customers.direct.list.all_statuses') }}</flux:button>
                    <flux:menu class="max-h-72 overflow-y-auto">
                        <flux:menu.item wire:click="$set('statusId', '')">{{ __('customers.direct.list.all_statuses') }}</flux:menu.item>
                        @foreach ($options['statuses'] ?? [] as $status)
                            <flux:menu.item wire:click="$set('statusId', '{{ $status['id'] }}')">{{ $status['name'] }}</flux:menu.item>
                        @endforeach
                    </flux:menu>
                </flux:dropdown>
                @if (auth()->user()->isSuperAdmin() || auth()->user()->isOperationsDirector())
                <flux:dropdown>
                    <flux:button class="w-36 rounded-full bg-zinc-100 dark:bg-zinc-800" variant="ghost" size="sm" icon:trailing="chevron-down">{{ $selectedOwner['name'] ?? __('customers.direct.list.all_owners') }}</flux:button>
                    <flux:menu class="max-h-72 overflow-y-auto">
                        <flux:menu.item wire:click="$set('ownerId', '')">{{ __('customers.direct.list.all_owners') }}</flux:menu.item>
                        @foreach ($ownerCandidates as $owner)
                            <flux:menu.item wire:click="$set('ownerId', '{{ $owner['id'] }}')">{{ $owner['name'] }}</flux:menu.item>
                        @endforeach
                    </flux:menu>
                </flux:dropdown>
                @endif
                @if ($hasFilters)
                    <flux:button wire:click="clearFilters" variant="ghost" size="sm" icon="x-mark">{{ __('customers.direct.list.clear') }}</flux:button>
                @endif
            </div>
            <div class="flex flex-wrap items-center gap-2 border-t border-zinc-100 pt-4 dark:border-zinc-800">
                <span class="mr-1 text-sm font-medium text-zinc-500">{{ __('customers.direct.list.created_date') }}</span>
                <flux:button wire:click="applyDatePreset('today')" variant="ghost" size="sm">{{ __('customers.direct.list.today') }}</flux:button>
                <flux:button wire:click="applyDatePreset('month')" variant="ghost" size="sm">{{ __('customers.direct.list.this_month') }}</flux:button>
                <x-date-time-picker id="direct-customers-created-from" wire:model.live.debounce.400ms="createdFrom" :value="$createdFrom" :placeholder="__('customers.direct.list.created_from')" :aria-label="__('customers.direct.list.created_from')" class="w-full rounded-full border-transparent bg-zinc-100 dark:bg-zinc-800 sm:w-40" size="sm" />
                <span class="text-zinc-400" aria-hidden="true">—</span>
                <x-date-time-picker id="direct-customers-created-to" wire:model.live.debounce.400ms="createdTo" :value="$createdTo" :placeholder="__('customers.direct.list.created_to')" :aria-label="__('customers.direct.list.created_to')" class="w-full rounded-full border-transparent bg-zinc-100 dark:bg-zinc-800 sm:w-40" size="sm" />
            </div>
            @if ($errors->has('createdFrom') || $errors->has('createdTo'))
                <div class="text-sm text-red-600">@error('createdFrom')<p>{{ $message }}</p>@enderror @error('createdTo')<p>{{ $message }}</p>@enderror</div>
            @endif
        </div>

        <div class="crm-table-wrap mt-5">
            <table class="crm-table">
                <thead><tr><th>{{ __('customers.direct.list.columns.customer') }}</th><th>{{ __('customers.direct.list.columns.contact') }}</th><th>{{ __('customers.direct.list.columns.channel') }}</th><th>{{ __('customers.direct.list.columns.owner') }}</th><th>{{ __('customers.direct.list.columns.followup') }}</th><th>{{ __('customers.direct.list.columns.status') }}</th><th>{{ __('customers.direct.list.columns.created_at') }}</th></tr></thead>
                <tbody>
                    @forelse ($customers as $customer)
                        <tr wire:key="direct-customer-{{ $customer['id'] }}">
                            <td><a class="font-semibold text-teal-700 hover:underline" href="{{ route('direct-customers.show', $customer['id']) }}" wire:navigate>{{ $customer['name'] }}</a><div class="text-xs text-zinc-500">{{ $customer['code'] }}</div></td>
                            <td>{{ $customer['contact_masked'] }}</td>
                            <td class="font-semibold">{{ $customer['source'] }}</td>
                            <td>{{ $customer['owner'] ?: __('customers.fallback.unset') }}</td>
                            <td>
                                <div class="text-xs text-zinc-500">{{ __('customers.direct.list.last_followup') }}: {{ $customer['last_followup_on'] ?: __('customers.fallback.unset') }}</div>
                                @if ($customer['last_followup_content'])
                                    <div class="mt-1 max-w-64 truncate text-xs text-zinc-400" title="{{ $customer['last_followup_content'] }}">{{ $customer['last_followup_content'] }}</div>
                                @endif
                                @if ($customer['next_followup_at'])
                                    <a class="mt-1 inline-block text-sm text-teal-700 hover:underline dark:text-teal-300" href="{{ route('reminders.index', ['customerIdFilter' => $customer['id']]) }}" wire:navigate>{{ __('customers.direct.list.next_followup') }}: {{ \Carbon\CarbonImmutable::parse($customer['next_followup_at'])->format('Y-m-d H:i') }}</a>
                                @else
                                    <div class="mt-1 text-sm text-zinc-500">{{ __('customers.direct.list.no_next_followup') }}</div>
                                @endif
                                <span class="crm-pill mt-1 {{ $customer['followup_status'] === 'overdue' ? 'tone-red' : ($customer['followup_status'] === 'today' ? 'tone-amber' : 'tone-blue') }}">{{ __('customers.direct.list.followup_statuses.'.$customer['followup_status']) }}</span>
                            </td>
                            <td><span class="crm-pill tone-blue">{{ $customer['status'] }}</span></td>
                            <td>{{ $customer['created_at'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-10 text-center text-zinc-500">{{ __('customers.direct.list.empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-5">{{ $customers->links() }}</div>
    </section>
</div>
