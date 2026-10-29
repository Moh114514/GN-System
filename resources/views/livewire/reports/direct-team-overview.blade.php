<div>
    <x-page-back :href="route('direct-customers.index')" :label="__('direct_team.back')" class="mb-4" />

    <section class="crm-section-header mb-6">
        <div>
            <h2 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-50">{{ __('direct_team.title') }}</h2>
            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ __('direct_team.description') }}</p>
            <p class="mt-1 text-xs text-zinc-500">{{ __('direct_team.date_basis', ['from' => $snapshot['from'], 'to' => $snapshot['to']]) }}</p>
        </div>
        <div class="flex flex-wrap gap-3">
            <div class="w-44">
                <flux:input type="month" wire:model.live="month" :label="__('direct_team.month')" />
                @error('month') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            @if ($snapshot['can_filter_owner'])
                <div class="w-56">
                    <flux:select wire:model.live="ownerId" :label="__('direct_team.owner')">
                        <flux:select.option value="">{{ __('direct_team.all_owners') }}</flux:select.option>
                        @foreach ($snapshot['owner_options'] as $owner)
                            <flux:select.option value="{{ $owner['id'] }}">{{ $owner['name'] }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
            @endif
        </div>
    </section>

    <section class="mb-6 grid gap-3 sm:grid-cols-3" aria-label="{{ __('direct_team.metrics.heading') }}">
        @foreach ([['amount_krw', __('direct_team.metrics.amount')], ['orders', __('direct_team.metrics.orders')], ['customers', __('direct_team.metrics.customers')]] as [$key, $label])
            <article class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <span class="text-sm text-zinc-500 dark:text-zinc-400">{{ $label }}</span>
                <strong class="mt-3 block text-2xl font-bold text-zinc-900 dark:text-zinc-50">
                    @if ($key === 'amount_krw') ₩ {{ number_format($snapshot['totals'][$key]) }} @else {{ number_format($snapshot['totals'][$key]) }} @endif
                </strong>
            </article>
        @endforeach
    </section>

    <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <h3 class="text-lg font-bold text-zinc-900 dark:text-zinc-50">{{ __('direct_team.performance') }}</h3>
        <div class="mt-4 overflow-x-auto">
            <table class="crm-table min-w-[680px]">
                <thead><tr><th>{{ __('direct_team.owner') }}</th><th>{{ __('direct_team.metrics.orders') }}</th><th>{{ __('direct_team.metrics.customers') }}</th><th>{{ __('direct_team.metrics.amount') }}</th><th>{{ __('direct_team.actions') }}</th></tr></thead>
                <tbody>
                    @forelse ($snapshot['owners'] as $owner)
                        <tr wire:key="direct-team-owner-{{ $owner['id'] }}">
                            <td class="font-semibold">{{ $owner['name'] }}</td>
                            <td>{{ number_format($owner['orders']) }}</td>
                            <td>{{ number_format($owner['customers']) }}</td>
                            <td>₩ {{ number_format($owner['amount_krw']) }}</td>
                            <td><a class="font-semibold text-teal-700 hover:underline" href="{{ route('orders.index', ['sourceTypeFilter' => 'direct', 'ownerIdFilter' => $owner['id'], 'dateField' => 'occurred_on', 'dateFrom' => $snapshot['from'], 'dateTo' => $snapshot['to']]) }}" wire:navigate>{{ __('direct_team.view_orders') }} →</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-8 text-center text-zinc-500">{{ __('direct_team.empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
