<div>
    <section class="crm-section-header">
        <div>
            <h2 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-50">{{ __('settlements.titles.center') }}</h2>
            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ __('settlements.center.description') }}</p>
        </div>
        <flux:button wire:click="generate" icon="play" variant="primary">{{ __('settlements.center.generate_latest') }}</flux:button>
    </section>

    <section class="mb-6 rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <h3 class="font-semibold">{{ __('settlements.center.cycle_configuration') }}</h3>
        <p class="mt-1 text-sm text-zinc-500">{{ __('settlements.center.cycle_description') }}</p>
        <form wire:submit="saveConfiguration" class="mt-4 grid items-end gap-3 sm:grid-cols-3">
            <div class="rounded-xl border border-zinc-200 px-3 py-2 dark:border-zinc-700">
                <div class="text-xs text-zinc-500">{{ __('settlements.center.period_natural_month') }}</div>
                <div class="mt-1 font-semibold">{{ __('settlements.center.generation_day') }}</div>
            </div>
            <flux:input wire:model="triggerTime" type="time" :label="__('settlements.center.trigger_time')" required />
            <flux:checkbox wire:model="confirmConfigurationChange" :label="__('settlements.center.confirm_old_config')" />
            <flux:button type="submit">{{ __('settlements.center.save_next_config') }}</flux:button>
        </form>
    </section>

    <section class="mb-6 rounded-2xl border border-amber-200 bg-amber-50 p-5 shadow-sm dark:border-amber-900 dark:bg-amber-950/30">
        <h3 class="font-semibold text-amber-900 dark:text-amber-100">{{ __('settlements.center.historical_heading') }}</h3>
        <p class="mt-1 text-sm text-amber-800 dark:text-amber-200">{{ __('settlements.center.historical_description') }}</p>
        <form wire:submit="generateHistorical" class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-end">
            <flux:select class="sm:min-w-96" wire:model="historicalPeriodEnd" :label="__('settlements.center.historical_period')" required>
                <flux:select.option value="">{{ __('settlements.center.select_period') }}</flux:select.option>
                @foreach ($historicalPeriods as $period)
                    <flux:select.option value="{{ $period->end->toDateString() }}">{{ $period->start->format('Y-m-d') }} {{ __('settlements.labels.date_to') }} {{ $period->end->format('Y-m-d') }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:button class="sm:mt-6" type="submit" variant="primary">{{ __('settlements.center.generate_historical') }}</flux:button>
        </form>
        @error('historicalPeriodEnd')<p class="mt-2 text-sm text-red-700 dark:text-red-300">{{ $message }}</p>@enderror
    </section>

    <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900" wire:poll.10s>
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <h3 class="font-semibold">{{ __('settlements.center.runs') }}</h3>
            @if ($availablePeriods->isNotEmpty())
                <flux:select class="sm:min-w-80" wire:model.live="selectedPeriodEnd" :label="__('settlements.center.selected_period')" size="sm">
                    @foreach ($availablePeriods as $period)
                        <flux:select.option value="{{ $period->period_end->toDateString() }}">{{ $period->period_start->format('Y-m-d') }} {{ __('settlements.labels.date_to') }} {{ $period->period_end->format('Y-m-d') }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif
        </div>
        <div class="crm-table-wrap mt-4"><table class="crm-table">
            <thead><tr><th>{{ __('settlements.center.period') }}</th><th>{{ __('settlements.center.progress') }}</th><th>{{ __('settlements.center.consumption_commission') }}</th><th>{{ __('settlements.center.status') }}</th><th></th></tr></thead>
            <tbody>
            @forelse ($runs as $run)
                @php($isCollapsed = in_array((string) $run->id, $collapsedRunIds, true))
                <tr wire:key="settlement-run-{{ $run->id }}" class="cursor-pointer" wire:click="toggleRun('{{ $run->id }}')" wire:keydown.enter="toggleRun('{{ $run->id }}')" wire:keydown.space.prevent="toggleRun('{{ $run->id }}')" tabindex="0" role="button" aria-label="{{ __($isCollapsed ? 'settlements.center.expand' : 'settlements.center.collapse') }}{{ __('settlements.center.runs') }}">
                    <td>{{ $run->period_start->format('Y-m-d') }} {{ __('settlements.labels.date_to') }} {{ $run->period_end->format('Y-m-d') }}<div class="text-xs text-zinc-500">{{ ['manual' => __('settlements.center.manual'), 'historical' => __('settlements.center.historical_manual'), 'scheduled' => __('settlements.center.scheduled')][$run->trigger_source] ?? $run->trigger_source }}</div></td>
                    <td>{{ $run->processed_agents + $run->existing_agents + $run->failed_agents }}/{{ $run->total_agents }}<div class="text-xs text-zinc-500">{{ __('settlements.center.generated_count', ['count' => $run->processed_agents]) }} · {{ __('settlements.center.existing_count', ['count' => $run->existing_agents]) }}</div><div class="text-xs text-red-600">{{ __('settlements.center.failed_count', ['count' => $run->failed_agents]) }}</div></td>
                    <td>₩{{ number_format($run->total_consumption_krw) }}<div class="text-xs text-zinc-500">{{ __('settlements.detail.commission') }} ₩{{ number_format($run->total_commission_krw) }}</div></td>
                    <td>{{ __('settlements.run_statuses.'.$run->status) }}<div class="text-xs text-zinc-500">{{ __('settlements.center.dingtalk', ['status' => __('settlements.notification_statuses.'.$run->notification_status)]) }}</div></td>
                    <td class="space-x-2" x-on:keydown.stop>
                        <button type="button" class="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-sm font-semibold text-zinc-700 hover:bg-zinc-100" wire:click.stop="toggleRun('{{ $run->id }}')" aria-expanded="{{ $isCollapsed ? 'false' : 'true' }}">
                            <flux:icon :name="$isCollapsed ? 'chevron-right' : 'chevron-down'" class="size-4" aria-hidden="true" />
                            <span>{{ __($isCollapsed ? 'settlements.center.expand' : 'settlements.center.collapse') }}</span>
                        </button>
                        @if ($run->failed_agents > 0)
                            <a class="text-sm font-semibold text-red-700 hover:underline" href="{{ route('settlements.runs.failures', $run->id) }}" wire:navigate x-on:click.stop>{{ __('settlements.center.view_failures', ['count' => $run->failed_agents]) }}</a>
                            <flux:button wire:click.stop="retry('{{ $run->id }}')" size="sm" variant="ghost">{{ __('settlements.center.retry_failed') }}</flux:button>
                        @endif
                        @if (in_array($run->notification_status, ['failed', 'disabled'], true))<flux:button wire:click.stop="retryNotification('{{ $run->id }}')" size="sm" variant="ghost">{{ __('settlements.center.retry_dingtalk') }}</flux:button>@endif
                        @if (($documentCounts[(string) $run->id] ?? 0) > 0)<a class="text-sm font-semibold text-teal-700" href="{{ route('settlements.archive', $run->id) }}" x-on:click.stop>{{ __('settlements.center.download_documents', ['count' => $documentCounts[(string) $run->id]]) }}</a>@endif
                    </td>
                </tr>
                @if (! $isCollapsed)
                    @if ($run->members->isNotEmpty())
                        @foreach ($run->members as $member)
                            @php($settlement = $member->settlement)
                            @php($agentDisplay = $memberDisplays[(string) $run->id][$member->id] ?? null)
                            <tr class="bg-zinc-50/70 dark:bg-zinc-800/40">
                                <td colspan="2">
                                    @if ($settlement)
                                        <a class="font-semibold text-teal-700 hover:underline" href="{{ route('settlements.show', $settlement->id) }}" wire:navigate>{{ $agentDisplay['code'] ?? '' }} {{ $agentDisplay['name'] ?? __('settlements.labels.unknown_agent').' #'.$member->agent_id }}</a>
                                    @else
                                        <span class="font-semibold">{{ $agentDisplay['code'] ?? '' }} {{ $agentDisplay['name'] ?? __('settlements.labels.unknown_agent').' #'.$member->agent_id }}</span>
                                    @endif
                                </td>
                                <td>{{ __('settlements.center.outcome_'.$member->outcome) }}</td>
                                <td>{{ $settlement ? __('settlements.detail.commission').' ₩'.number_format($settlement->total_commission_krw) : ($member->error_message_key ? __($member->error_message_key, $member->error_parameters ?? []) : '—') }}</td>
                                <td>{{ __('settlements.center.member_status_'.$member->outcome) }}</td>
                            </tr>
                        @endforeach
                    @else
                        @foreach ($run->settlements as $settlement)
                            <tr class="bg-zinc-50/70 dark:bg-zinc-800/40">
                                @php($agentDisplay = $legacyDisplays[$settlement->id] ?? ['code' => '', 'name' => __('settlements.labels.unknown_agent').' #'.$settlement->agent_id])
                                <td colspan="2"><a class="font-semibold text-teal-700 hover:underline" href="{{ route('settlements.show', $settlement->id) }}" wire:navigate>{{ $agentDisplay['code'] }} {{ $agentDisplay['name'] }}</a></td>
                                <td>{{ __('settlements.center.outcome_generated') }}</td>
                                <td>{{ __('settlements.detail.commission') }} ₩{{ number_format($settlement->total_commission_krw) }}</td>
                                <td>{{ __('settlements.center.member_status_generated') }}</td>
                            </tr>
                        @endforeach
                    @endif
                @endif
            @empty<tr><td colspan="5" class="py-8 text-center text-zinc-500">{{ __('settlements.center.empty') }}</td></tr>@endforelse
            </tbody>
        </table></div>
        <div class="mt-8 flex flex-col gap-4 border-t border-zinc-200 pt-5 dark:border-zinc-700 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h4 class="font-semibold">{{ __('settlements.archive.center_heading') }}</h4>
                <p class="mt-1 text-sm text-zinc-500">{{ __('settlements.archive.center_description') }}</p>
                <p class="mt-2 text-sm text-zinc-500">{{ __('settlements.archive.center_count', ['count' => $historicalSettlementCount]) }}</p>
            </div>
            <a class="shrink-0 text-sm font-semibold text-teal-700 hover:underline" href="{{ route('settlements.history') }}" wire:navigate>{{ __('settlements.archive.view_all') }}</a>
        </div>
    </section>
</div>
