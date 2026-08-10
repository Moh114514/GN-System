<div>
    <x-page-back :href="route('settlements.index')" :label="__('settlements.failure.back')" class="mb-4" />

    <section class="crm-section-header">
        <div>
            <p class="text-xs font-medium text-zinc-400">{{ __('settlements.failure.eyebrow') }}</p>
            <h2 class="mt-1 text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-50">{{ __('settlements.failure.batch', ['id' => $run->id]) }}</h2>
            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ $run->period_start->format('Y-m-d') }} {{ __('settlements.labels.date_to') }} {{ $run->period_end->format('Y-m-d') }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
            @if ($failures !== [])
                <a class="rounded-lg px-3 py-2 text-sm font-semibold text-teal-700 hover:bg-teal-50" href="{{ route('settlements.runs.failures.download', $run->id) }}">{{ __('settlements.failure.download_report') }}</a>
                <flux:button wire:click="retryAll" wire:loading.attr="disabled" wire:target="retryAll" variant="primary">{{ __('settlements.failure.retry_all') }}</flux:button>
            @endif
        </div>
    </section>

    <section class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="crm-card"><span class="text-xs text-zinc-500">{{ __('settlements.failure.run_status') }}</span><strong class="mt-1 block">{{ __('settlements.run_statuses.'.$run->status) }}</strong></div>
        <div class="crm-card"><span class="text-xs text-zinc-500">{{ __('settlements.failure.total_agents') }}</span><strong class="mt-1 block">{{ $run->total_agents }}</strong></div>
        <div class="crm-card"><span class="text-xs text-zinc-500">{{ __('settlements.failure.success_count') }}</span><strong class="mt-1 block text-emerald-700">{{ $run->processed_agents }}</strong></div>
        <div class="crm-card"><span class="text-xs text-zinc-500">{{ __('settlements.failure.existing_count') }}</span><strong class="mt-1 block text-sky-700">{{ $run->existing_agents }}</strong></div>
        <div class="crm-card"><span class="text-xs text-zinc-500">{{ __('settlements.failure.failed_count') }}</span><strong class="mt-1 block text-red-700">{{ $run->failed_agents }}</strong></div>
    </section>

    <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <h3 class="font-semibold">{{ __('settlements.failure.unresolved_heading') }}</h3>
        @if ($failures === [])
            <p class="mt-4 text-sm text-zinc-500">{{ __('settlements.failure.no_failures') }}</p>
        @else
            <div class="crm-table-wrap mt-4"><table class="crm-table">
                <thead><tr><th>{{ __('settlements.failure.agent_code') }}</th><th>{{ __('settlements.failure.agent_name') }}</th><th>{{ __('settlements.failure.agent_id') }}</th><th>{{ __('settlements.failure.reason') }}</th></tr></thead>
                <tbody>
                    @foreach ($failures as $failure)
                        <tr wire:key="settlement-failure-{{ $failure->agentId }}">
                            <td>{{ $failure->agentCode }}</td>
                            <td>{{ $failure->agentName }}</td>
                            <td>{{ $failure->agentId }}</td>
                            <td class="whitespace-pre-wrap">{{ $failure->reason }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        @endif
    </section>
</div>
