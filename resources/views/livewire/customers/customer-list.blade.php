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
            $hasFilters = $search !== '' || $statusId !== '' || $agentId !== '' || $institutionId !== '' || $perPage !== 20;
        @endphp

        <div class="flex flex-wrap items-center gap-2">
            <flux:input
                class="mr-1 w-full sm:w-72"
                wire:model.live.debounce.350ms="search"
                icon="magnifying-glass"
                :placeholder="__('customers.list.search_placeholder')"
                size="sm"
            />

            <flux:dropdown>
                <flux:button class="rounded-full bg-zinc-100 dark:bg-zinc-800" variant="ghost" size="sm" icon:trailing="chevron-down">
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
                <flux:button class="rounded-full bg-zinc-100 dark:bg-zinc-800" variant="ghost" size="sm" icon:trailing="chevron-down">
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
                <flux:button class="rounded-full bg-zinc-100 dark:bg-zinc-800" variant="ghost" size="sm" icon:trailing="chevron-down">
                    {{ $selectedInstitution['name'] ?? __('customers.list.all_institutions') }}
                </flux:button>
                <flux:menu class="max-h-72 overflow-y-auto">
                    <flux:menu.item wire:click="$set('institutionId', '')">{{ __('customers.list.all_institutions') }}</flux:menu.item>
                    @foreach ($options['institutions'] as $institution)
                        <flux:menu.item wire:click="$set('institutionId', '{{ $institution['id'] }}')">{{ $institution['name'] }}</flux:menu.item>
                    @endforeach
                </flux:menu>
            </flux:dropdown>

            <x-localized-date-picker
                id="customers-created-from"
                wire:model.live.debounce.400ms="createdFrom"
                :value="$createdFrom"
                :label="__('customers.list.created_from')"
                size="sm"
            />
            <x-localized-date-picker
                id="customers-created-to"
                wire:model.live.debounce.400ms="createdTo"
                :value="$createdTo"
                :label="__('customers.list.created_to')"
                size="sm"
            />

            <flux:dropdown>
                <flux:button class="rounded-full bg-zinc-100 dark:bg-zinc-800" variant="ghost" size="sm" icon:trailing="chevron-down">
                    {{ __('customers.list.per_page', ['count' => $perPage]) }}
                </flux:button>
                <flux:menu>
                    @foreach ([20, 50, 100] as $size)
                        <flux:menu.item wire:click="$set('perPage', {{ $size }})">{{ __('customers.list.per_page', ['count' => $size]) }}</flux:menu.item>
                    @endforeach
                </flux:menu>
            </flux:dropdown>

            @if ($errors->has('createdFrom') || $errors->has('createdTo'))
                <div class="basis-full text-sm text-red-600">
                    @error('createdFrom')<p>{{ $message }}</p>@enderror
                    @error('createdTo')<p>{{ $message }}</p>@enderror
                </div>
            @endif

            @if ($hasFilters || $createdFrom !== '' || $createdTo !== '')
                <flux:button wire:click="clearFilters" variant="ghost" size="sm" icon="x-mark">{{ __('customers.list.clear') }}</flux:button>
            @endif
        </div>

        <div class="crm-table-wrap mt-5">
            <table class="crm-table">
                <thead>
                    <tr>
                        <th>{{ __('customers.list.columns.customer') }}</th>
                        <th>{{ __('customers.list.columns.contact') }}</th>
                        <th>{{ __('customers.list.columns.document') }}</th>
                        <th>{{ __('customers.list.columns.source') }}</th>
                        <th>{{ __('customers.list.columns.status') }}</th>
                        <th>{{ __('customers.list.columns.created_at') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($customers as $customer)
                        <tr wire:key="customer-{{ $customer['id'] }}">
                            <td>
                                <a class="font-semibold text-teal-700 hover:underline" href="{{ route('customers.show', $customer['id']) }}" wire:navigate>
                                    {{ $customer['name'] }}
                                </a>
                                <div class="text-xs text-zinc-500">{{ $customer['code'] }}</div>
                            </td>
                            <td>{{ $customer['contact_masked'] }}</td>
                            <td>{{ $customer['document_masked'] }}</td>
                            <td class="font-semibold">{{ $customer['source'] }}</td>
                            <td><span class="crm-pill tone-blue">{{ $customer['status'] }}</span></td>
                            <td>{{ $customer['created_at'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-10 text-center text-zinc-500">{{ __('customers.list.empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-5">{{ $customers->links() }}</div>
    </section>
</div>
