<div>
    <x-page-back :href="route('configuration.index')" :label="__('config.back_to_configuration')" class="mb-4" />
    <section class="crm-section-header">
        <div>
            <p class="text-xs font-medium text-zinc-400">{{ __('config.direct_customer.eyebrow') }}</p>
            <h2 class="mt-1 text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-50">{{ __('config.direct_customer.title') }}</h2>
            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ __('config.direct_customer.description') }}</p>
        </div>
    </section>

    <livewire:direct-customer-channel-configuration />

    <section class="grid gap-6 xl:grid-cols-[24rem_1fr]">
        <form wire:submit="saveRate" class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <h3 class="font-semibold">{{ __('config.direct_customer.form_heading') }}</h3>
            <div class="mt-4 space-y-3">
                <flux:input wire:model="rateBps" type="number" min="0" max="10000" :label="__('config.direct_customer.rate_bps')" />
                <flux:input wire:model="effectiveFrom" type="date" :label="__('config.direct_customer.effective_from')" />
                <flux:input wire:model="effectiveUntil" type="date" :label="__('config.direct_customer.effective_until')" />
                <flux:textarea wire:model="reason" :label="__('config.direct_customer.reason')" />
                <flux:button type="submit" variant="primary">{{ __('config.direct_customer.save') }}</flux:button>
            </div>
        </form>

        <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <h3 class="font-semibold">{{ __('config.direct_customer.history_heading') }}</h3>
            <div class="crm-table-wrap mt-4">
                <table class="crm-table">
                    <thead><tr><th>{{ __('config.direct_customer.table.rate') }}</th><th>{{ __('config.direct_customer.table.period') }}</th><th>{{ __('config.direct_customer.table.reason') }}</th></tr></thead>
                    <tbody>
                        @forelse ($configuration['history'] as $rate)
                            <tr>
                                <td>{{ number_format($rate['rate_bps'] / 100, 2) }}%</td>
                                <td>{{ $rate['effective_from'] }} — {{ $rate['effective_until'] ?? __('config.direct_customer.open_ended') }}</td>
                                <td>{{ $rate['reason'] }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3">{{ __('config.direct_customer.empty') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</div>
