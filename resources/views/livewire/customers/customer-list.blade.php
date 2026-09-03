<div>
    <section class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h2 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-50">{{ __('customers.title.list') }}</h2>
            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ __('customers.list.description') }}</p>
        </div>
        <div class="flex shrink-0 gap-2 sm:justify-end">
            @if (auth()->user()->is_super_admin)
                <flux:button :href="route('customer-statuses.index')" variant="ghost" size="sm" wire:navigate>{{ __('customers.list.status_configuration') }}</flux:button>
            @endif
            <flux:button :href="route('customers.create')" variant="primary" size="sm" icon="plus" wire:navigate>{{ __('customers.list.create') }}</flux:button>
        </div>
    </section>

    <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        @php
            $selectedStatus = collect($options['statuses'])->firstWhere('id', (int) $statusId);
            $selectedAgent = collect($options['agents'])->firstWhere('id', (int) $agentId);
            $selectedInstitution = collect($options['institutions'])->firstWhere('id', (int) $institutionId);
            $selectedOwner = collect($ownerCandidates)->firstWhere('id', (int) $ownerId);
            $hasFilters = $search !== '' || $statusId !== '' || $agentId !== '' || $institutionId !== '' || $ownerId !== '' || $ownerState !== '' || $transferStatus !== '' || $createdFrom !== '' || $createdTo !== '' || $perPage !== 20;
        @endphp

        <div class="space-y-4">
            <div class="flex flex-wrap items-center gap-2">
            <flux:input
                class="w-full sm:w-72"
                wire:model.live.debounce.350ms="search"
                icon="magnifying-glass"
                :placeholder="__('customers.list.search_placeholder')"
                size="sm"
            />

            <flux:dropdown>
                <flux:button class="w-32 rounded-full bg-zinc-100 dark:bg-zinc-800" variant="ghost" size="sm" icon:trailing="chevron-down">
                    {{ $selectedStatus['name'] ?? __('customers.list.all_statuses') }}
                </flux:button>
                <flux:menu class="max-h-72 overflow-y-auto">
                    <flux:menu.item wire:click="$set('statusId', '')">{{ __('customers.list.all_statuses') }}</flux:menu.item>
                    @foreach ($options['statuses'] as $status)
                        <flux:menu.item wire:click="$set('statusId', '{{ $status['id'] }}')">{{ $status['name'] }}</flux:menu.item>
                    @endforeach
                </flux:menu>
            </flux:dropdown>

            <flux:dropdown>
                <flux:button class="w-36 rounded-full bg-zinc-100 dark:bg-zinc-800" variant="ghost" size="sm" icon:trailing="chevron-down">
                    {{ $ownerState === 'unassigned' ? __('customers.list.unassigned_owners') : ($ownerState === 'invalid' ? __('customers.list.invalid_owners') : __('customers.list.all_owner_states')) }}
                </flux:button>
                <flux:menu>
                    <flux:menu.item wire:click="$set('ownerState', '')">{{ __('customers.list.all_owner_states') }}</flux:menu.item>
                    <flux:menu.item wire:click="$set('ownerState', 'unassigned')">{{ __('customers.list.unassigned_owners') }}</flux:menu.item>
                    <flux:menu.item wire:click="$set('ownerState', 'invalid')">{{ __('customers.list.invalid_owners') }}</flux:menu.item>
                </flux:menu>
            </flux:dropdown>

            <flux:dropdown>
                <flux:button class="w-36 rounded-full bg-zinc-100 dark:bg-zinc-800" variant="ghost" size="sm" icon:trailing="chevron-down">
                    {{ $transferStatus === 'pending' ? __('customers.list.pending_transfers') : __('customers.list.all_transfer_statuses') }}
                </flux:button>
                <flux:menu>
                    <flux:menu.item wire:click="$set('transferStatus', '')">{{ __('customers.list.all_transfer_statuses') }}</flux:menu.item>
                    <flux:menu.item wire:click="$set('transferStatus', 'pending')">{{ __('customers.list.pending_transfers') }}</flux:menu.item>
                </flux:menu>
            </flux:dropdown>

            <flux:dropdown>
                <flux:button class="w-36 rounded-full bg-zinc-100 dark:bg-zinc-800" variant="ghost" size="sm" icon:trailing="chevron-down">
                    {{ $selectedAgent['name'] ?? __('customers.list.all_agents') }}
                </flux:button>
                <flux:menu class="max-h-72 overflow-y-auto">
                    <flux:menu.item wire:click="$set('agentId', '')">{{ __('customers.list.all_agents') }}</flux:menu.item>
                    @foreach ($options['agents'] as $agent)
                        <flux:menu.item wire:click="$set('agentId', '{{ $agent['id'] }}')">{{ $agent['name'] }}</flux:menu.item>
                    @endforeach
                </flux:menu>
            </flux:dropdown>

            <flux:dropdown>
                <flux:button class="w-36 rounded-full bg-zinc-100 dark:bg-zinc-800" variant="ghost" size="sm" icon:trailing="chevron-down">
                    {{ $selectedInstitution['name'] ?? __('customers.list.all_institutions') }}
                </flux:button>
                <flux:menu class="max-h-72 overflow-y-auto">
                    <flux:menu.item wire:click="$set('institutionId', '')">{{ __('customers.list.all_institutions') }}</flux:menu.item>
                    @foreach ($options['institutions'] as $institution)
                        <flux:menu.item wire:click="$set('institutionId', '{{ $institution['id'] }}')">{{ $institution['name'] }}</flux:menu.item>
                    @endforeach
                </flux:menu>
            </flux:dropdown>

            <flux:dropdown>
                <flux:button class="w-36 rounded-full bg-zinc-100 dark:bg-zinc-800" variant="ghost" size="sm" icon:trailing="chevron-down">
                    {{ $selectedOwner['name'] ?? __('customers.list.all_owners') }}
                </flux:button>
                <flux:menu class="max-h-72 overflow-y-auto">
                    <flux:menu.item wire:click="$set('ownerId', '')">{{ __('customers.list.all_owners') }}</flux:menu.item>
                    @foreach ($ownerCandidates as $owner)
                        <flux:menu.item wire:click="$set('ownerId', '{{ $owner['id'] }}')">{{ $owner['name'] }}</flux:menu.item>
                    @endforeach
                </flux:menu>
            </flux:dropdown>

            @if ($hasFilters)
                <flux:button wire:click="clearFilters" variant="ghost" size="sm" icon="x-mark">{{ __('customers.list.clear') }}</flux:button>
            @endif
            </div>

            <div class="flex flex-wrap items-center gap-2 border-t border-zinc-100 pt-4 dark:border-zinc-800">
                <span class="mr-1 text-sm font-medium text-zinc-500">{{ __('customers.list.created_date') }}</span>
                <flux:button wire:click="applyDatePreset('today')" variant="ghost" size="sm">{{ __('customers.list.today') }}</flux:button>
                <flux:button wire:click="applyDatePreset('month')" variant="ghost" size="sm">{{ __('customers.list.this_month') }}</flux:button>
                <flux:button wire:click="applyDatePreset('year')" variant="ghost" size="sm">{{ __('customers.list.this_year') }}</flux:button>
                <x-date-time-picker
                    id="customers-created-from"
                    wire:model.live.debounce.400ms="createdFrom"
                    :value="$createdFrom"
                    :placeholder="__('customers.list.created_from')"
                    :aria-label="__('customers.list.created_from')"
                    class="w-full rounded-full border-transparent bg-zinc-100 dark:bg-zinc-800 sm:w-40"
                    size="sm"
                />
                <span class="text-zinc-400" aria-hidden="true">—</span>
                <x-date-time-picker
                    id="customers-created-to"
                    wire:model.live.debounce.400ms="createdTo"
                    :value="$createdTo"
                    :placeholder="__('customers.list.created_to')"
                    :aria-label="__('customers.list.created_to')"
                    class="w-full rounded-full border-transparent bg-zinc-100 dark:bg-zinc-800 sm:w-40"
                    size="sm"
                />
                <div class="sm:ml-auto">
                    <flux:dropdown>
                        <flux:button class="w-28 rounded-full bg-zinc-100 dark:bg-zinc-800" variant="ghost" size="sm" icon:trailing="chevron-down">
                            {{ __('customers.list.per_page', ['count' => $perPage]) }}
                        </flux:button>
                        <flux:menu>
                            @foreach ([20, 50, 100] as $size)
                                <flux:menu.item wire:click="$set('perPage', {{ $size }})">{{ __('customers.list.per_page', ['count' => $size]) }}</flux:menu.item>
                            @endforeach
                        </flux:menu>
                    </flux:dropdown>
                </div>
            </div>

            @if ($errors->has('createdFrom') || $errors->has('createdTo'))
                <div class="text-sm text-red-600">
                    @error('createdFrom')<p>{{ $message }}</p>@enderror
                    @error('createdTo')<p>{{ $message }}</p>@enderror
                </div>
            @endif

            @if (auth()->user()->is_super_admin || auth()->user()->isBdManager())
                <div class="rounded-xl border border-teal-100 bg-teal-50/60 p-4 dark:border-teal-900 dark:bg-teal-950/20">
                    <div class="flex flex-wrap items-center gap-3">
                        <span class="font-medium">{{ __('customers.list.bulk_transfer') }}</span>
                        <span class="text-sm text-zinc-500">{{ __('customers.list.selected_count', ['count' => count($selectedCustomerIds)]) }}</span>
                    </div>
                    <div class="mt-3 grid gap-3 md:grid-cols-[minmax(0,16rem)_minmax(0,1fr)_auto]">
                        <flux:select wire:model="bulkTransferTargetOwnerId" :label="__('customers.list.target_owner')">
                            <flux:select.option value="">{{ __('customers.form.select') }}</flux:select.option>
                            @foreach ($ownerCandidates as $owner)
                                <flux:select.option value="{{ $owner['id'] }}">{{ $owner['name'] }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:input wire:model="bulkTransferReason" :label="__('customers.list.transfer_reason')" />
                        <flux:button wire:click="bulkTransfer" variant="primary" class="self-end">{{ __('customers.list.bulk_transfer_submit') }}</flux:button>
                    </div>
                </div>
            @endif
        </div>

        <div class="crm-table-wrap mt-5">
            <table class="crm-table">
                <thead>
                    <tr>
                        @if (auth()->user()->is_super_admin || auth()->user()->isBdManager())
                            <th><span class="sr-only">{{ __('customers.list.columns.customer') }}</span></th>
                        @endif
                        <th>{{ __('customers.list.columns.customer') }}</th>
                        <th>{{ __('customers.list.columns.contact') }}</th>
                        <th>{{ __('customers.list.columns.document') }}</th>
                        <th>{{ __('customers.list.columns.source') }}</th>
                        <th>{{ __('customers.list.columns.owner') }}</th>
                        <th>{{ __('customers.list.columns.status') }}</th>
                        <th>{{ __('customers.list.columns.created_at') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($customers as $customer)
                        <tr wire:key="customer-{{ $customer['id'] }}">
                            @if (auth()->user()->is_super_admin || auth()->user()->isBdManager())
                                <td><flux:checkbox wire:model.live="selectedCustomerIds" value="{{ $customer['id'] }}" /></td>
                            @endif
                            <td>
                                <a class="font-semibold text-teal-700 hover:underline" href="{{ route('customers.show', $customer['id']) }}" wire:navigate>
                                    {{ $customer['name'] }}
                                </a>
                                <div class="text-xs text-zinc-500">{{ $customer['code'] }}</div>
                            </td>
                            <td>{{ $customer['contact_masked'] }}</td>
                            <td>{{ $customer['document_masked'] }}</td>
                            <td class="font-semibold">{{ $customer['source'] }}</td>
                            <td>{{ $customer['owner'] ?: __('customers.fallback.unset') }}</td>
                            <td><span class="crm-pill tone-blue">{{ $customer['status'] }}</span></td>
                            <td>{{ $customer['created_at'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ auth()->user()->is_super_admin || auth()->user()->isBdManager() ? 8 : 7 }}" class="py-10 text-center text-zinc-500">{{ __('customers.list.empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-5">{{ $customers->links() }}</div>
    </section>
</div>
