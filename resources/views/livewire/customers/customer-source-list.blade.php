<div>
    <x-page-back :href="route('dashboard')" :label="__('customers.source_list.back_to_dashboard')" class="mb-4" />

    <section class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h2 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-50">{{ __('customers.source_list.title') }}</h2>
            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">
                {{ __('customers.source_list.description') }}
                @if ($createdTo !== '')<span>· {{ __('customers.source_list.as_of', ['date' => $createdTo]) }}</span>@endif
            </p>
        </div>
        <span class="crm-pill tone-blue">{{ __('customers.source_list.count', ['count' => number_format($customers->total())]) }}</span>
    </section>

    <section class="crm-card p-4 sm:p-5">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
            <flux:input
                wire:model.live.debounce.400ms="search"
                :label="__('customers.source_list.search')"
                :placeholder="__('customers.source_list.search_placeholder')"
                class="w-full sm:max-w-md"
            />
            <flux:select wire:model.live="sourceType" :label="__('customers.source_list.source')" class="w-full sm:max-w-xs">
                <option value="">{{ __('customers.source_list.all_sources') }}</option>
                @foreach ($sourceTypes as $source)
                    <option value="{{ $source['value'] }}">{{ $source['label'] }}</option>
                @endforeach
            </flux:select>
        </div>
        @error('createdTo')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror

        <div class="crm-table-wrap mt-5">
            <table class="crm-table">
                <thead>
                    <tr>
                        <th>{{ __('customers.source_list.columns.customer') }}</th>
                        <th>{{ __('customers.source_list.columns.source_type') }}</th>
                        <th>{{ __('customers.source_list.columns.source_detail') }}</th>
                        <th>{{ __('customers.source_list.columns.owner') }}</th>
                        <th>{{ __('customers.source_list.columns.status') }}</th>
                        <th>{{ __('customers.source_list.columns.created_at') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($customers as $customer)
                        @php($detailRoute = $customer['source_type'] === 'direct' ? 'direct-customers.show' : 'customers.show')
                        <tr wire:key="all-customer-{{ $customer['id'] }}">
                            <td>
                                <a class="font-semibold text-teal-700 hover:underline dark:text-teal-300" href="{{ route($detailRoute, $customer['id']) }}" wire:navigate>
                                    {{ $customer['name'] }}
                                </a>
                                <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $customer['code'] }}</div>
                            </td>
                            <td>{{ __('customers.source_list.types.'.$customer['source_type']) }}</td>
                            <td>{{ $customer['source'] }}</td>
                            <td>{{ $customer['owner'] ?: __('customers.fallback.unset') }}</td>
                            <td><span class="crm-pill tone-blue">{{ $customer['status'] }}</span></td>
                            <td>{{ $customer['created_at'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-10 text-center text-zinc-500 dark:text-zinc-400">{{ __('customers.source_list.empty', ['source' => $selectedSourceLabel]) }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-5">{{ $customers->links() }}</div>
    </section>
</div>
