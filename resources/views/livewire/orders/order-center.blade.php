<div>
    <section class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h2 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-50">{{ __('orders.title') }}</h2>
            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ __('orders.center.description') }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
            @if (auth()->user()?->is_super_admin)
                <flux:button :href="route('orders.recycle-bin')" wire:navigate variant="ghost" size="sm">{{ __('orders.center.recycle_bin') }}</flux:button>
            @endif
            <flux:button wire:click="openCreate" variant="primary" size="sm" icon="plus">{{ __('orders.center.create') }}</flux:button>
        </div>
    </section>


    @if ($showCreate)
        <section class="mb-6 rounded-2xl border border-teal-200 bg-white p-5 shadow-sm dark:border-teal-800 dark:bg-zinc-900">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h3 class="font-semibold">{{ __('orders.center.create') }}</h3>
                    <p class="mt-1 text-sm text-zinc-500">{{ __('orders.center.create_description') }}</p>
                </div>
                <flux:button wire:click="closeCreate" variant="ghost" size="sm" icon="x-mark">{{ __('orders.center.close') }}</flux:button>
            </div>


            <form wire:submit="save" class="mt-5">
                <div class="grid gap-5 xl:grid-cols-[20rem_minmax(0,1fr)]">
                    <div>
                        @if ($selectedCustomer)
                            <div class="rounded-xl border border-teal-200 bg-teal-50 p-4 dark:border-teal-800 dark:bg-teal-950">
                                <div class="text-xs text-teal-700 dark:text-teal-300">{{ __('orders.center.selected_customer') }}</div>
                                <div class="mt-1 font-semibold">{{ $selectedCustomer['name'] }}</div>
                                <div class="text-sm text-zinc-500">{{ $selectedCustomer['code'] }}</div>
                                <flux:button class="mt-3" wire:click="clearCustomer" type="button" variant="ghost" size="sm">{{ __('orders.center.reselect_customer') }}</flux:button>
                            </div>
                        @else
                            <flux:input wire:model.live.debounce.300ms="customerSearch" :label="__('orders.center.search_customer')" icon="magnifying-glass" :placeholder="__('orders.center.customer_placeholder')" />
                            @error('customerId') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                            <div class="mt-3 max-h-72 space-y-2 overflow-y-auto">
                                @forelse ($customerCandidates as $customer)
                                    <button
                                        type="button"
                                        wire:click="selectCustomer({{ $customer['id'] }})"
                                        class="w-full rounded-xl border border-zinc-200 px-3 py-2 text-left transition hover:border-teal-400 hover:bg-teal-50 dark:border-zinc-700 dark:hover:bg-teal-950"
                                    >
                                        <span class="block font-semibold">{{ $customer['name'] }}</span>
                                        <span class="text-xs text-zinc-500">{{ $customer['code'] }}</span>
                                    </button>
                                @empty
                                    <p class="rounded-xl bg-zinc-50 px-3 py-4 text-center text-sm text-zinc-500 dark:bg-zinc-800">{{ __('orders.center.no_matching_customers') }}</p>
                                @endforelse
                            </div>
                        @endif
                    </div>

                    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        <flux:select wire:model="institutionId" :label="__('orders.fields.institution')" required>
                            <flux:select.option value="">{{ __('orders.fields.select') }}</flux:select.option>
                            @foreach ($options['institutions'] as $institution)
                                <flux:select.option value="{{ $institution['id'] }}">{{ $institution['name'] }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:select wire:model="agentId" :label="__('orders.fields.agent')" required>
                            <flux:select.option value="">{{ __('orders.fields.select') }}</flux:select.option>
                            @foreach ($options['agents'] as $agent)
                                <flux:select.option value="{{ $agent['id'] }}">{{ $agent['code'] }} · {{ $agent['name'] }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:select wire:model="treatmentProjectId" :label="__('orders.fields.treatment_project')">
                            <flux:select.option value="">{{ __('orders.fields.manual_project') }}</flux:select.option>
                            @foreach ($options['treatment_projects'] as $project)
                                <flux:select.option value="{{ $project['id'] }}">{{ $project['name'] }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:input wire:model="projectName" :label="__('orders.fields.project_snapshot')" :required="$treatmentProjectId === ''" />
                        <flux:input wire:model="amountKrw" type="number" min="0" step="1" :label="__('orders.fields.amount')" required />
                        <flux:select wire:model.live="orderStatus" :label="__('orders.fields.status')" required>
                            <flux:select.option value="pending">{{ __('orders.statuses.pending') }}</flux:select.option>
                            <flux:select.option value="completed">{{ __('orders.statuses.completed') }}</flux:select.option>
                        </flux:select>
                        @if ($orderStatus === 'completed')
                            <flux:input wire:model="completedOn" type="datetime-local" :label="__('orders.fields.completed_at')" required />
                        @endif
                        <flux:select wire:model="translatorLanguageId" :label="__('orders.fields.translator_language')">
                            <flux:select.option value="">{{ __('orders.fields.unselected') }}</flux:select.option>
                            @foreach ($options['translator_languages'] as $language)
                                <flux:select.option value="{{ $language['id'] }}">{{ $language['name'] }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:input wire:model="translatorName" :label="__('orders.fields.translator_name')" />
                        <div class="md:col-span-2 xl:col-span-3">
                            <flux:textarea wire:model="notes" :label="__('orders.fields.notes')" rows="2" />
                        </div>
                        <div class="md:col-span-2 xl:col-span-3 flex justify-end">
                            <flux:button type="submit" variant="primary">{{ __('orders.center.save') }}</flux:button>
                        </div>
                    </div>
                </div>
            </form>
        </section>
    @endif

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
                <flux:select.option value="pending">{{ __('orders.statuses.pending') }}</flux:select.option>
                <flux:select.option value="completed">{{ __('orders.statuses.completed') }}</flux:select.option>
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
                        <tr wire:key="managed-order-{{ $order['id'] }}">
                            <td class="w-[32rem] max-w-[32rem]"><a class="line-clamp-2 overflow-hidden whitespace-normal break-words font-semibold leading-5 text-teal-700 hover:underline" title="{{ $order['project_name'] }}" href="{{ route('orders.show', $order['id']) }}" wire:navigate>{{ $order['project_name'] }}</a><div class="mt-1 text-xs text-zinc-500">#{{ $order['id'] }}</div></td>
                            <td><a class="font-semibold text-teal-700 hover:underline" href="{{ route('customers.show', $order['customer_id']) }}" wire:navigate>{{ $order['customer_name'] }}</a><div class="text-xs text-zinc-500">{{ $order['customer_code'] }}</div></td>
                            <td>{{ $order['institution'] }}<div class="text-xs text-zinc-500">{{ __('orders.sources.agent') }} · {{ $order['source'] }}</div></td>
                            <td>₩ {{ number_format($order['amount_krw']) }}</td>
                            <td><span class="crm-pill {{ $order['status'] === 'completed' ? 'tone-green' : ($order['status'] === 'cancelled' ? 'tone-red' : 'tone-amber') }}">{{ ['pending' => __('orders.statuses.pending'), 'completed' => __('orders.statuses.completed'), 'cancelled' => __('orders.statuses.cancelled')][$order['status']] ?? $order['status'] }}</span></td>
                            <td>{{ $order['completed_at'] ?? $order['created_at'] }}<div class="text-xs text-zinc-500">{{ $order['completed_at'] ? __('orders.fields.completed_time') : __('orders.fields.created_at') }}</div></td>
                            <td>
                                <flux:button href="{{ route('orders.show', $order['id']) }}" wire:navigate variant="ghost" size="sm">{{ __('orders.center.view_details') }}</flux:button>
                                @if ($order['status'] === 'pending')
                                    <flux:button wire:click="complete({{ $order['id'] }})" :wire:confirm="__('orders.center.mark_complete_confirm')" size="sm">{{ __('orders.center.mark_complete') }}</flux:button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-10 text-center text-zinc-500">{{ __('orders.center.no_orders') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-5">{{ $orders->links() }}</div>
    </section>
</div>
