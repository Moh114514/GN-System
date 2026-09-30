<div>
    <section class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h2 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-50">{{ __('orders.title') }}</h2>
            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ __('orders.center.description') }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <flux:button href="{{ route('institution-returns.index') }}" wire:navigate variant="primary" size="sm" icon="arrow-up-tray">{{ __('orders.institution_return.title') }}</flux:button>
            @if (auth()->user()?->is_super_admin)
                <flux:button :href="route('orders.recycle-bin')" wire:navigate variant="ghost" size="sm">{{ __('orders.center.recycle_bin') }}</flux:button>
            @endif
        </div>
    </section>

    <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        @php
            $selectedInstitution = collect($options['institutions'])->firstWhere('id', (int) $institutionFilter);
            $selectedAgent = collect($options['agents'])->firstWhere('id', (int) $agentFilter);
            $hasFilters = $search !== '' || $statusFilter !== '' || $institutionFilter !== '' || $agentFilter !== '' || $dateFrom !== '' || $dateTo !== '' || $sourceTypeFilter !== '' || $dateField !== 'created_at' || $perPage !== 20;
        @endphp
        <div class="flex flex-wrap items-center gap-2">
            <flux:input class="mr-1 w-full sm:w-72" wire:model.live.debounce.350ms="search" icon="magnifying-glass" :placeholder="__('orders.center.search')" size="sm" />
            <flux:select class="w-32" wire:model.live="statusFilter" size="sm" :aria-label="__('orders.fields.status_filter')">
                <flux:select.option value="">{{ __('orders.fields.all_statuses') }}</flux:select.option>
                <flux:select.option value="pending">{{ __('orders.statuses.pending') }}</flux:select.option>
                <flux:select.option value="completed">{{ __('orders.statuses.completed') }}</flux:select.option>
                <flux:select.option value="cancelled">{{ __('orders.statuses.cancelled') }}</flux:select.option>
            </flux:select>
            <flux:select class="w-36" wire:model.live="sourceTypeFilter" size="sm" :aria-label="__('orders.fields.source_filter')">
                <flux:select.option value="">{{ __('orders.fields.all_sources') }}</flux:select.option>
                <flux:select.option value="agent">{{ __('orders.sources.agent') }}</flux:select.option>
                <flux:select.option value="direct">{{ __('orders.sources.direct') }}</flux:select.option>
            </flux:select>
            <flux:select class="w-36" wire:model.live="dateField" size="sm" :aria-label="__('orders.fields.date_field')">
                <flux:select.option value="created_at">{{ __('orders.fields.created_date') }}</flux:select.option>
                <flux:select.option value="completed_at">{{ __('orders.fields.completed_date') }}</flux:select.option>
            </flux:select>
            <flux:input class="w-40" type="date" wire:model.live="dateFrom" size="sm" :aria-label="__('orders.fields.date_from')" />
            <flux:input class="w-40" type="date" wire:model.live="dateTo" size="sm" :aria-label="__('orders.fields.date_to')" />
            <flux:dropdown>
                <flux:button class="rounded-full bg-zinc-100 dark:bg-zinc-800" variant="ghost" size="sm" icon:trailing="chevron-down">{{ $selectedInstitution['name'] ?? __('orders.center.all_institutions') }}</flux:button>
                <flux:menu class="max-h-72 overflow-y-auto">
                    <flux:menu.item wire:click="$set('institutionFilter', '')">{{ __('orders.center.all_institutions') }}</flux:menu.item>
                    @foreach ($options['institutions'] as $institution)
                        <flux:menu.item wire:click="$set('institutionFilter', '{{ $institution['id'] }}')">{{ $institution['name'] }}</flux:menu.item>
                    @endforeach
                </flux:menu>
            </flux:dropdown>
            @if ($options['agents'] !== [])
            <flux:dropdown>
                <flux:button class="rounded-full bg-zinc-100 dark:bg-zinc-800" variant="ghost" size="sm" icon:trailing="chevron-down">{{ $selectedAgent['name'] ?? __('orders.center.all_agents') }}</flux:button>
                <flux:menu class="max-h-72 overflow-y-auto">
                    <flux:menu.item wire:click="$set('agentFilter', '')">{{ __('orders.center.all_agents') }}</flux:menu.item>
                    @foreach ($options['agents'] as $agent)
                        <flux:menu.item wire:click="$set('agentFilter', '{{ $agent['id'] }}')">{{ $agent['name'] }}</flux:menu.item>
                    @endforeach
                </flux:menu>
            </flux:dropdown>
            @endif
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

        @error('dateTo')
            <p class="mt-2 text-sm text-red-600" role="alert">{{ $message }}</p>
        @enderror
        <div class="mt-5 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
            @forelse ($orders as $order)
                <a wire:key="order-card-{{ $order['id'] }}" href="{{ route('orders.show', $order['id']) }}" wire:navigate aria-label="{{ __('orders.fields.order') }} #{{ $order['id'] }}: {{ $order['project_name'] }}" class="group flex min-h-[230px] min-w-0 flex-col rounded-xl border border-zinc-300 bg-zinc-50/50 p-3 shadow-sm transition hover:border-teal-500 hover:shadow-md dark:border-zinc-600 dark:bg-zinc-800/40">
                    <div class="flex min-w-0 items-start justify-between gap-3">
                        <div class="min-w-0">
                            <span class="line-clamp-2 overflow-hidden break-words font-semibold leading-5 text-teal-700 group-hover:underline" title="{{ $order['project_name'] }}">{{ $order['project_name'] }}</span>
                            <div class="mt-1 text-xs text-zinc-500">#{{ $order['id'] }}</div>
                        </div>
                        <span class="crm-pill shrink-0 {{ $order['status'] === 'completed' ? 'tone-green' : ($order['status'] === 'cancelled' ? 'tone-red' : 'tone-amber') }}">{{ ['pending' => __('orders.statuses.pending'), 'completed' => __('orders.statuses.completed'), 'cancelled' => __('orders.statuses.cancelled')][$order['status']] ?? $order['status'] }}</span>
                    </div>

                    <dl class="mt-2 grid gap-1.5 text-sm">
                        <div class="min-w-0">
                            <dt class="sr-only">{{ __('orders.fields.customer') }}</dt>
                            <dd class="mt-0.5 min-w-0"><span class="line-clamp-2 break-words font-semibold">{{ $order['customer_name'] }}</span><span class="block truncate text-xs text-zinc-500">{{ $order['customer_code'] }}</span></dd>
                        </div>
                        <div class="min-w-0">
                            <dt class="text-xs text-zinc-500">{{ __('orders.fields.institution') }}</dt>
                            <dd class="mt-1 line-clamp-2 break-words font-medium">{{ $order['institution'] }}</dd>
                        </div>
                        <div class="min-w-0">
                            <dt class="sr-only">{{ $order['source_type'] === 'direct' ? __('orders.sources.direct') : __('orders.sources.agent') }}</dt>
                            <dd class="mt-0.5 truncate text-xs font-medium text-zinc-600 dark:text-zinc-300">{{ $order['source_type'] === 'direct' ? __('orders.sources.direct') : __('orders.sources.agent') }} · {{ $order['source'] }}</dd>
                        </div>
                    </dl>

                    <div class="mt-auto flex flex-wrap items-end justify-between gap-2 border-t border-zinc-200 pt-2 dark:border-zinc-700">
                        <div>
                            <div class="text-xs text-zinc-500">{{ __('orders.fields.transaction_amount') }}</div>
                            <div class="mt-1 text-lg font-semibold tabular-nums text-zinc-900 dark:text-zinc-50">₩ {{ number_format($order['amount_krw']) }}</div>
                        </div>
                        <div class="text-right text-xs">
                            @if ($order['occurred_on'])
                                <div class="text-xs text-zinc-500">{{ __('orders.fields.occurred_on') }}</div>
                                <div class="mt-1 tabular-nums">{{ $order['occurred_on'] }}</div>
                            @endif
                            @if ($order['status'] === 'completed' && $order['completion_precision'] === 'datetime' && $order['completed_at'])
                                <div class="{{ $order['occurred_on'] ? 'mt-1' : '' }} text-xs text-zinc-500">{{ __('orders.fields.completed_time') }}</div>
                                <div class="mt-0.5 tabular-nums">{{ $order['completed_at'] }}</div>
                            @elseif ($order['status'] === 'completed' && $order['completion_precision'] === 'date' && $order['occurred_on'])
                                <div class="text-xs text-zinc-500">{{ __('orders.fields.completed_date') }}</div>
                                <div class="mt-0.5 tabular-nums">{{ $order['occurred_on'] }}</div>
                            @elseif ($order['status'] !== 'completed' && ! $order['occurred_on'] && $order['created_at'])
                                <div class="text-xs text-zinc-500">{{ __('orders.fields.created_at') }}</div>
                                <div class="mt-1 tabular-nums">{{ $order['created_at'] }}</div>
                            @endif
                        </div>
                    </div>
                    <span class="mt-1 self-end text-lg leading-none text-zinc-400 transition group-hover:translate-x-0.5 group-hover:text-teal-700" aria-hidden="true">→</span>
                </a>
            @empty
                <div class="col-span-full py-10 text-center text-zinc-500">{{ __('orders.center.no_orders') }}</div>
            @endforelse
        </div>
        <div class="mt-5">{{ $orders->links() }}</div>
    </section>
</div>
