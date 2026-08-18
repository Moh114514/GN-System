<div>
    @php
        $settlementCenterQuery = request()->filled('selectedPeriodEnd')
            ? ['selectedPeriodEnd' => request()->query('selectedPeriodEnd')]
            : [];
    @endphp
    <x-page-back :href="$isHistoricalArchive ? route('settlements.history') : route('settlements.index', $settlementCenterQuery)" :label="$isHistoricalArchive ? __('settlements.detail.back_history') : __('settlements.detail.back')" class="mb-4" />

    @php
        $canRegenerateGeneration = in_array($settlement->generation_status, ['pending', 'unverified'], true);
        $needsRegeneration = in_array($settlement->status, ['pending_review', 'rejected']) && $canRegenerateGeneration && $settlement->settlement_run_id !== null;
        $generationUnverified = $settlement->generation_status === 'unverified';
        $generationNotApplicable = $settlement->generation_status === 'not_applicable';
        $generationRecoveryRequired = $generationUnverified && $settlement->settlement_run_id === null;
        $canRefresh = in_array($settlement->status, ['pending_review', 'rejected'], true) && $settlement->generation_status === 'generated' && $freshness?->isStale();
    @endphp
    @if ($canRefresh)
        <section id="settlement-freshness-alert" data-business-alert tabindex="-1" class="scroll-mt-20 mb-5 rounded-xl border border-amber-300 bg-amber-50 px-5 py-4 text-amber-900 dark:border-amber-700 dark:bg-amber-950/30 dark:text-amber-100">
            <h3 class="font-semibold">{{ __('settlements.detail.freshness_heading') }}</h3>
            <p class="mt-1 text-sm">{{ __('settlements.detail.freshness_description') }}</p>
            <div class="mt-3 grid gap-3 text-sm sm:grid-cols-2">
                <div class="rounded-lg bg-white/60 p-3 dark:bg-zinc-900/40"><strong>{{ __('settlements.detail.current_settlement') }}</strong><br>{{ $freshness->settlementItemCount }} {{ __('settlements.detail.orders_count') }} · ₩{{ number_format($freshness->settlementConsumptionKrw) }} · {{ __('settlements.detail.commission') }} ₩{{ number_format($freshness->settlementCommissionKrw) }}</div>
                <div class="rounded-lg bg-white/60 p-3 dark:bg-zinc-900/40"><strong>{{ __('settlements.detail.current_orders') }}</strong><br>{{ $freshness->currentItemCount }} {{ __('settlements.detail.orders_count') }} · ₩{{ number_format($freshness->currentConsumptionKrw) }} · {{ __('settlements.detail.commission') }} ₩{{ number_format($freshness->currentCommissionKrw) }}</div>
            </div>
            <form wire:submit="refreshSettlement" class="mt-4 space-y-3">
                <flux:textarea wire:model="refreshReason" :label="__('settlements.detail.refresh_reason')" rows="2" required />
                <flux:button type="submit" wire:loading.attr="disabled" wire:target="refreshSettlement" variant="primary">{{ __('settlements.detail.refresh_settlement') }}</flux:button>
            </form>
        </section>
    @elseif ($freshness?->isStale())
        <section id="settlement-freshness-alert" data-business-alert tabindex="-1" class="scroll-mt-20 mb-5 rounded-xl border border-amber-300 bg-amber-50 px-5 py-4 text-amber-900 dark:border-amber-700 dark:bg-amber-950/30 dark:text-amber-100">
            <h3 class="font-semibold">{{ __('settlements.detail.freshness_heading') }}</h3>
            <p class="mt-1 text-sm">{{ __('settlements.detail.freshness_locked_description') }}</p>
        </section>
    @endif
    @if ($needsRegeneration)
        <section id="{{ $needsRegeneration ? 'settlement-generation-alert' : 'settlement-generation-regeneration-note' }}" data-business-alert data-business-alert-key="{{ $settlement->id }}-{{ $settlement->generation_status }}-regeneration" tabindex="-1" class="scroll-mt-20 mb-5 rounded-xl border border-amber-300 bg-amber-50 px-5 py-4 text-amber-900 dark:border-amber-700 dark:bg-amber-950/30 dark:text-amber-100">
            <h3 class="font-semibold">{{ __('settlements.detail.generation_pending_heading') }}</h3>
            <p class="mt-1 text-sm">{{ __('settlements.detail.generation_pending_description') }}</p>
            <flux:button class="mt-3" wire:click="regenerateSettlement" wire:loading.attr="disabled" wire:target="regenerateSettlement" variant="primary">{{ __('settlements.detail.regenerate_settlement') }}</flux:button>
        </section>
    @endif
    @if ($generationUnverified)
        <section id="{{ $needsRegeneration ? 'settlement-generation-unverified-note' : 'settlement-generation-alert' }}" data-business-alert data-business-alert-key="{{ $settlement->id }}-{{ $settlement->generation_status }}-unverified" tabindex="-1" class="scroll-mt-20 mb-5 rounded-xl border border-red-300 bg-red-50 px-5 py-4 text-red-900 dark:border-red-700 dark:bg-red-950/30 dark:text-red-100">
            <h3 class="font-semibold">{{ __('settlements.detail.generation_unverified_heading') }}</h3>
            <p class="mt-1 text-sm">{{ __('settlements.detail.generation_unverified_description') }}</p>
            @if ($generationRecoveryRequired && auth()->user()?->is_super_admin)
                <form wire:submit="createRecoveryBatch" class="mt-4 space-y-3">
                    <flux:textarea wire:model="generationRecoveryBasis" :label="__('settlements.detail.recovery_basis')" rows="3" required />
                    <div class="flex flex-wrap gap-2">
                        <flux:button type="submit" wire:loading.attr="disabled" wire:target="createRecoveryBatch" variant="primary">{{ __('settlements.detail.create_recovery_batch') }}</flux:button>
                        <flux:button type="button" wire:click="recoverUnverifiedAsHistorical" wire:loading.attr="disabled" wire:target="recoverUnverifiedAsHistorical" variant="ghost">{{ __('settlements.detail.recover_as_historical') }}</flux:button>
                    </div>
                </form>
            @elseif ($settlement->settlement_run_id !== null)
                <p class="mt-2 text-sm">{{ __('settlements.detail.recovery_batch_exists') }}</p>
            @endif
        </section>
    @endif
    @if ($generationNotApplicable)
        <section class="mb-5 rounded-xl border border-zinc-300 bg-zinc-50 px-5 py-4 text-zinc-800 dark:border-zinc-700 dark:bg-zinc-950/30 dark:text-zinc-100">
            <h3 class="font-semibold">{{ __('settlements.detail.historical_heading') }}</h3>
            <p class="mt-1 text-sm">{{ __('settlements.detail.historical_description') }}</p>
        </section>
    @endif

    <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div><p class="text-xs font-medium text-zinc-400">{{ __('settlements.detail.eyebrow') }}</p><h2 class="mt-1 text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-50">{{ $agentDisplay['code'] }} {{ $agentDisplay['name'] }}</h2><p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ $settlement->period_start->format('Y-m-d') }} {{ __('settlements.labels.date_to') }} {{ $settlement->period_end->format('Y-m-d') }}</p></div>
            <div class="flex flex-wrap items-center justify-end gap-3">
                <div class="flex items-center gap-2" :aria-label="__('settlements.detail.batch_navigation')">
                    @if ($previousSettlement)
                        <a class="text-sm font-semibold text-teal-700 hover:underline" href="{{ route('settlements.show', ['settlement' => $previousSettlement->id] + $settlementCenterQuery) }}" wire:navigate>{{ __('settlements.detail.previous') }}</a>
                    @else
                        <span class="text-sm text-zinc-400">{{ __('settlements.detail.previous') }}</span>
                    @endif
                    @if ($nextSettlement)
                        <a class="text-sm font-semibold text-teal-700 hover:underline" href="{{ route('settlements.show', ['settlement' => $nextSettlement->id] + $settlementCenterQuery) }}" wire:navigate>{{ __('settlements.detail.next') }}</a>
                    @else
                        <span class="text-sm text-zinc-400">{{ __('settlements.detail.next') }}</span>
                    @endif
                </div>
                <span class="crm-pill tone-blue">{{ __('settlements.settlement_statuses.'.$settlement->status) }}</span>
            </div>
        </div>
        <dl class="mt-5 grid gap-4 sm:grid-cols-3"><div><dt class="text-xs text-zinc-500">{{ __('settlements.detail.consumption_total') }}</dt><dd class="font-semibold">₩ {{ number_format($settlement->total_consumption_krw) }}</dd></div><div><dt class="text-xs text-zinc-500">{{ __('settlements.detail.commission_total') }}</dt><dd class="font-semibold">₩ {{ number_format($settlement->total_commission_krw) }}</dd></div><div><dt class="text-xs text-zinc-500">{{ $settlementCurrency === 'KRW' ? __('settlements.detail.payable_krw') : __('settlements.detail.payable_cny') }}</dt><dd class="font-semibold">@if ($settlementCurrency === 'KRW')₩ {{ number_format($settlement->total_commission_krw) }}@elseif ($settlement->exchange_rate_krw_per_cny)¥ {{ number_format($settlement->total_commission_krw / (float) $settlement->exchange_rate_krw_per_cny, 2) }}@else{{ __('settlements.labels.pending') }}@endif</dd></div></dl>
    </section>

    @if (in_array($settlement->status, ['pending_review', 'rejected']) && ! $generationNotApplicable && ! $generationRecoveryRequired)
        <section class="mt-6 grid gap-5 lg:grid-cols-2">
            <form wire:submit="approve" class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                <h3 class="font-semibold">{{ __('settlements.detail.approve_heading') }}</h3>
                <flux:select wire:model.live="settlementCurrency" class="mt-3" :label="__('settlements.detail.currency')" required><flux:select.option value="KRW">KRW</flux:select.option><flux:select.option value="CNY">CNY</flux:select.option></flux:select>
                @if ($settlementCurrency === 'KRW')
                    <p class="mt-2 text-sm text-zinc-500">{{ __('settlements.detail.krw_no_conversion_hint') }}</p>
                @endif
                @if ($settlementCurrency === 'CNY')
                @if ($settlement->exchange_rate_quote_error)
                    <p class="mt-2 text-sm text-amber-700 dark:text-amber-300">{{ __('settlements.detail.quote_unavailable_hint') }}{{ __($settlement->exchange_rate_quote_error_key ?: 'settlements.quote_failures.unavailable', $settlement->exchange_rate_quote_error_parameters ?? []) }}</p>
                @elseif ($settlement->exchange_rate_quote_status === 'available')
                    <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ __('settlements.detail.quote_filled_hint', ['time' => $settlement->exchange_rate_quoted_at?->format('Y-m-d H:i')]) }}</p>
                @else
                    <p class="mt-2 text-sm text-amber-700 dark:text-amber-300">{{ __('settlements.detail.quote_empty_hint') }}</p>
                @endif
                <div class="mt-3 flex items-end gap-3">
                    <flux:input wire:model.live="exchangeRate" class="flex-1" type="number" step="0.000001" min="0.000001" :label="__('settlements.detail.exchange_rate')" required />
                    <flux:button type="button" wire:click="refreshExchangeRateQuote" wire:loading.attr="disabled" wire:target="refreshExchangeRateQuote" variant="ghost">{{ __('settlements.detail.refresh_quote') }}</flux:button>
                </div>
                @if ($settlement->exchange_rate_krw_per_cny)
                    @php
                        $quoteSource = $settlement->exchange_rate_quote_source ?: ($settlement->exchange_rate_source ?: __('settlements.detail.manual_source'));
                        $quoteTime = $settlement->exchange_rate_quoted_at?->format('Y-m-d H:i') ?: __('settlements.labels.pending');
                        $estimatedPayout = (float) $settlement->total_commission_krw / (float) $settlement->exchange_rate_krw_per_cny;
                    @endphp
                    <div class="mt-3 rounded-lg bg-zinc-50 px-3 py-2 text-sm text-zinc-600 dark:bg-zinc-800/70 dark:text-zinc-300">
                        <p>{{ __('settlements.detail.quote_snapshot', ['rate' => number_format((float) $settlement->exchange_rate_krw_per_cny, 6), 'source' => $quoteSource, 'time' => $quoteTime]) }}</p>
                        <p class="mt-1 font-semibold">{{ __('settlements.detail.estimated_payable') }}: ¥ {{ number_format($estimatedPayout, 2) }}</p>
                    </div>
                @endif
                @endif
                <flux:button class="mt-3" type="submit" variant="primary" :disabled="$needsRegeneration || $generationUnverified || $generationNotApplicable">{{ __('settlements.detail.approve_generate') }}</flux:button>
            </form>
            <form wire:submit="reject" class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900"><h3 class="font-semibold">{{ __('settlements.detail.reject_heading') }}</h3><flux:textarea wire:model="rejectionReason" class="mt-3" :label="__('settlements.detail.rejection_reason')" rows="2" required /><flux:button class="mt-3" type="submit" variant="danger">{{ __('settlements.detail.reject_settlement') }}</flux:button></form>
        </section>
    @elseif ($settlement->status === 'approved')
        <div class="mt-6"><flux:button wire:click="settle" variant="primary">{{ __('settlements.detail.settle') }}</flux:button></div>
    @endif

    @if (in_array($settlement->status, ['approved', 'settled']))
        <section class="mt-6 rounded-2xl border border-amber-200 bg-amber-50 p-5 dark:border-amber-900 dark:bg-amber-950/30">
            <h3 class="font-semibold">{{ __('settlements.detail.controlled_correction') }}</h3><p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">{{ __('settlements.detail.controlled_correction_description') }}</p>
            <form wire:submit="correctStatus" class="mt-3 grid gap-3 sm:grid-cols-2"><flux:select wire:model="correctionTarget" :label="__('settlements.detail.target_status')" required><flux:select.option value="pending_review">{{ __('settlements.settlement_statuses.pending_review') }}</flux:select.option><flux:select.option value="approved">{{ __('settlements.detail.approved_status') }}</flux:select.option><flux:select.option value="settled">{{ __('settlements.settlement_statuses.settled') }}</flux:select.option></flux:select><flux:textarea wire:model="correctionReason" :label="__('settlements.detail.correction_reason')" rows="2" required /><div class="sm:col-span-2"><flux:button type="submit" variant="danger">{{ __('settlements.detail.submit_correction') }}</flux:button></div></form>
        </section>
    @endif

    <section class="mt-6 rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex items-center justify-between"><h3 class="font-semibold">{{ __('settlements.detail.settlement_items') }}</h3><div class="flex gap-2">@if ($needsRegeneration)<flux:button wire:click="regenerateSettlement" size="sm" variant="primary">{{ __('settlements.detail.regenerate_settlement') }}</flux:button>@elseif (in_array($settlement->status, ['approved', 'settled']) || (in_array($settlement->status, ['paid', 'reconciled']) && $settlement->generation_status === 'not_applicable'))<flux:button wire:click="regenerateDocuments" size="sm" variant="ghost">{{ __('settlements.detail.documents_regenerate') }}</flux:button>@endif @foreach ($documents as $document)<a class="text-sm font-semibold text-teal-700" href="{{ route('settlements.documents.download', $document->id) }}">{{ __('settlements.detail.download_document', ['format' => strtoupper($document->format)]) }}</a>@endforeach</div></div>
        @if ($settlement->generation_status === 'generated' && $items->isEmpty())
            <p class="mt-3 text-sm text-zinc-500">{{ __('settlements.detail.zero_order_hint') }}</p>
        @endif
        <div class="crm-table-wrap mt-4"><table class="crm-table"><thead><tr><th>{{ __('settlements.detail.order') }}</th><th>{{ __('settlements.detail.project_date') }}</th><th>{{ __('settlements.detail.consumption') }}</th><th>{{ __('settlements.detail.rate') }}</th><th>{{ __('settlements.detail.commission') }}</th></tr></thead><tbody>
            @forelse ($items as $item)
                @php
                    $snapshot = is_string($item->rule_snapshot) ? json_decode($item->rule_snapshot, true) : (array) $item->rule_snapshot;
                    $orderId = (int) data_get($snapshot, 'order.id');
                @endphp
                <tr>
                    <td>
                        @if (in_array($orderId, $existingOrderIds, true))
                            <a class="font-semibold text-teal-700 hover:underline" href="{{ route('orders.show', $orderId) }}" wire:navigate>#{{ $orderId }}</a>
                        @else
                            <span class="font-semibold">#{{ $orderId }}</span><div class="text-xs text-zinc-500">{{ __('settlements.archive.archived_order') }}</div>
                        @endif
                    </td>
                    <td class="w-[32rem] max-w-[32rem]">
                        @if (in_array($orderId, $existingOrderIds, true))
                            <a class="line-clamp-2 overflow-hidden whitespace-normal break-words font-semibold leading-5 text-teal-700 hover:underline" href="{{ route('orders.show', $orderId) }}" wire:navigate title="{{ data_get($snapshot, 'order.project_name') }}">{{ data_get($snapshot, 'order.project_name') }}</a>
                        @else
                            <div class="line-clamp-2 overflow-hidden whitespace-normal break-words" title="{{ data_get($snapshot, 'order.project_name') }}">{{ data_get($snapshot, 'order.project_name') }}</div>
                        @endif
                        <div class="mt-1 text-xs text-zinc-500">{{ data_get($snapshot, 'order.completed_on') }}</div>
                    </td>
                    <td>₩ {{ number_format($item->consumption_krw) }}</td><td>{{ number_format(data_get($snapshot, 'rate_bps', 0) / 100, 2) }}%</td><td>₩ {{ number_format($item->commission_krw) }}</td>
                </tr>
            @empty<tr><td colspan="5" class="py-8 text-center text-zinc-500">{{ __('settlements.detail.items_empty') }}</td></tr>@endforelse
        </tbody></table></div>
    </section>

    @if ($suggestion)
        <section class="mt-6 rounded-2xl border border-amber-200 bg-amber-50 p-5 dark:border-amber-900 dark:bg-amber-950/30">
            <h3 class="font-semibold">{{ __('settlements.detail.grade_suggestion') }}</h3><p class="mt-2 text-sm">{{ __('settlements.detail.grade_suggestion_description', ['amount' => number_format($suggestion->monthly_commission_krw), 'current' => $suggestion->current_grade_id, 'recommended' => $suggestion->recommended_grade_id]) }}</p>
            @if ($suggestion->status === 'pending')<flux:input wire:model="suggestionReason" class="mt-3" :label="__('settlements.detail.review_note')" /><div class="mt-3 flex gap-2"><flux:button wire:click="reviewSuggestion({{ $suggestion->id }}, true)">{{ __('settlements.detail.approve_suggestion') }}</flux:button><flux:button wire:click="reviewSuggestion({{ $suggestion->id }}, false)" variant="ghost">{{ __('settlements.detail.keep_grade') }}</flux:button></div>@else<p class="mt-2 text-sm">{{ __('settlements.detail.result', ['status' => $suggestion->status]) }}</p>@endif
        </section>
    @endif
</div>
