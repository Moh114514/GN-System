<div>
    <x-page-back :href="route('settlements.index')" :label="__('settlements.archive.back')" class="mb-4" />

    <section class="crm-section-header">
        <div>
            <h2 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-50">{{ __('settlements.archive.title') }}</h2>
            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ __('settlements.archive.description') }}</p>
        </div>
    </section>

    <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex flex-wrap items-center gap-2">
            <flux:input class="w-full sm:w-72" wire:model.live.debounce.350ms="search" icon="magnifying-glass" :placeholder="__('settlements.archive.search')" size="sm" />
            <flux:input wire:model.live="month" type="month" :label="__('settlements.archive.month')" size="sm" />
            <flux:select wire:model.live="agentId" class="w-52" :label="__('settlements.archive.agent')" size="sm">
                <flux:select.option value="">{{ __('settlements.archive.all_agents') }}</flux:select.option>
                @foreach ($agentOptions as $agent)
                    <flux:select.option value="{{ $agent['id'] }}">{{ $agent['code'] }} · {{ $agent['name'] }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:select wire:model.live="status" class="w-44" :label="__('settlements.archive.status')" size="sm">
                <flux:select.option value="">{{ __('settlements.archive.all_statuses') }}</flux:select.option>
                    <flux:select.option value="draft">{{ __('settlements.settlement_statuses.draft') }}</flux:select.option>
                    <flux:select.option value="paid">{{ __('settlements.settlement_statuses.paid') }}</flux:select.option>
                <flux:select.option value="reconciled">{{ __('settlements.settlement_statuses.reconciled') }}</flux:select.option>
            </flux:select>
            @if ($month !== '' || $agentId !== '' || $status !== '' || $search !== '')
                <flux:button wire:click="clearFilters" variant="ghost" size="sm" icon="x-mark">{{ __('settlements.archive.clear') }}</flux:button>
            @endif
        </div>

        <div class="mt-4 text-sm text-zinc-500">{{ __('settlements.archive.count', ['count' => $settlements->total()]) }}</div>

        <div class="crm-table-wrap mt-5">
            <table class="crm-table">
                <thead><tr><th>{{ __('settlements.archive.columns.month') }}</th><th>{{ __('settlements.archive.columns.agent') }}</th><th>{{ __('settlements.archive.columns.consumption') }}</th><th>{{ __('settlements.archive.columns.commission') }}</th><th>{{ __('settlements.archive.columns.status') }}</th><th>{{ __('settlements.archive.columns.action') }}</th></tr></thead>
                <tbody>
                    @forelse ($settlements as $settlement)
                        @php($agent = $agentDisplays[$settlement->id] ?? ['code' => '', 'name' => __('settlements.labels.unknown_agent').' #'.$settlement->agent_id])
                        <tr wire:key="historical-settlement-{{ $settlement->id }}">
                            <td>{{ $settlement->period_start->format('Y-m') }}</td>
                            <td>{{ $agent['code'] }}<div class="text-xs text-zinc-500">{{ $agent['name'] }}</div></td>
                            <td>₩{{ number_format($settlement->total_consumption_krw) }}</td>
                            <td>₩{{ number_format($settlement->total_commission_krw) }}</td>
                            <td><span class="crm-pill tone-blue">{{ __('settlements.settlement_statuses.'.$settlement->status) }}</span></td>
                            <td><a class="font-semibold text-teal-700 hover:underline" href="{{ route('settlements.show', $settlement->id) }}" wire:navigate>{{ __('settlements.archive.view') }}</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-10 text-center text-zinc-500">{{ __('settlements.archive.empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-5">{{ $settlements->links() }}</div>
    </section>
</div>
