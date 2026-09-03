<div class="space-y-6">
    <x-page-back :href="route('dashboard')" :label="__('settlements.bd_commission.back')" />

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="text-sm text-zinc-500">{{ __('settlements.bd_commission.eyebrow') }}</p>
            <h1 class="text-2xl font-semibold">{{ __('settlements.bd_commission.title') }}</h1>
            <p class="mt-1 text-sm text-zinc-600">{{ __('settlements.bd_commission.description') }}</p>
        </div>
        <div class="flex flex-wrap items-end gap-2">
            <flux:input type="date" wire:model="quarterStart" label="{{ __('settlements.bd_commission.quarter') }}" />
            <flux:button wire:click="preview" variant="ghost">{{ __('settlements.bd_commission.preview') }}</flux:button>
            @if (auth()->user()->is_super_admin)
                <flux:button wire:click="generate" variant="primary">{{ __('settlements.bd_commission.generate') }}</flux:button>
            @endif
        </div>
    </div>

    @if ($error)<p class="rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ $error }}</p>@endif

    @if ($previewData !== [])
        <section class="grid gap-4 md:grid-cols-4" aria-label="{{ __('settlements.bd_commission.preview_title') }}">
            <div class="rounded-xl border bg-white p-4"><span class="text-sm text-zinc-500">{{ __('settlements.bd_commission.item_count') }}</span><strong class="mt-1 block text-xl">{{ number_format($previewData['item_count']) }}</strong></div>
            <div class="rounded-xl border bg-white p-4"><span class="text-sm text-zinc-500">{{ __('settlements.bd_commission.basis') }}</span><strong class="mt-1 block text-xl">₩ {{ number_format($previewData['basis_krw']) }}</strong></div>
            <div class="rounded-xl border bg-white p-4"><span class="text-sm text-zinc-500">{{ __('settlements.bd_commission.adjustment') }}</span><strong class="mt-1 block text-xl">₩ {{ number_format($previewData['adjustment_krw']) }}</strong></div>
            <div class="rounded-xl border bg-white p-4"><span class="text-sm text-zinc-500">{{ __('settlements.bd_commission.total') }}</span><strong class="mt-1 block text-xl">₩ {{ number_format($previewData['total_commission_krw']) }}</strong></div>
        </section>
    @endif

    <section class="rounded-xl border bg-white p-5">
        <h2 class="text-lg font-semibold">{{ __('settlements.bd_commission.periods') }}</h2>
        <div class="mt-4 overflow-x-auto">
            <table class="min-w-full text-left text-sm">
                <thead><tr class="border-b text-zinc-500"><th class="p-2">{{ __('settlements.bd_commission.quarter') }}</th><th class="p-2">{{ __('settlements.bd_commission.status') }}</th><th class="p-2">{{ __('settlements.bd_commission.total') }}</th><th class="p-2">{{ __('settlements.bd_commission.actions') }}</th></tr></thead>
                <tbody>
                    @forelse ($periods as $period)
                        <tr class="border-b"><td class="p-2">{{ $period->quarter_start->format('Y-m-d') }} — {{ $period->quarter_end->format('Y-m-d') }}</td><td class="p-2">{{ __('settlements.bd_commission.statuses.'.$period->status) }}</td><td class="p-2">₩ {{ number_format($period->total_commission_krw) }}</td><td class="p-2"><flux:button size="sm" wire:click="$set('selectedPeriodId', {{ $selectedPeriodId === $period->id ? 'null' : $period->id }})">{{ $selectedPeriodId === $period->id ? __('settlements.bd_commission.collapse') : __('settlements.bd_commission.view') }}</flux:button></td></tr>
                    @empty
                        <tr><td colspan="4" class="p-4 text-center text-zinc-500">{{ __('settlements.bd_commission.empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    @if ($detail)
        <section class="rounded-xl border bg-white p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-lg font-semibold">{{ __('settlements.bd_commission.detail') }}</h2>
                <div class="flex flex-wrap items-center gap-2">
                    @if (auth()->user()->is_super_admin && count($availableBdUsers) > 0)
                        <flux:select wire:model.live="selectedBdUserId" aria-label="{{ __('settlements.bd_commission.bd') }}">
                            <flux:select.option value="">{{ __('settlements.bd_commission.allocation') }}</flux:select.option>
                            @foreach ($availableBdUsers as $bd)
                                <flux:select.option value="{{ $bd['id'] }}">{{ $bd['name'] }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    @endif
                    @if ($detail['period']->items->isNotEmpty() || $detail['period']->adjustments->isNotEmpty())
                        @php
                            $exportBdId = auth()->user()->is_super_admin
                                ? ($selectedBdUserId === '' ? null : (int) $selectedBdUserId)
                                : ($detail['items'][0]['bd_user_id'] ?? $detail['adjustments'][0]['bd_user_id'] ?? null);
                        @endphp
                        @if ($exportBdId !== null)
                            <details class="relative">
                                <summary class="cursor-pointer list-none rounded-lg border border-zinc-300 px-3 py-1.5 text-sm font-semibold text-teal-700">{{ __('settlements.detail.export') }}</summary>
                                <div class="absolute right-0 z-10 mt-2 flex min-w-28 flex-col rounded-lg border border-zinc-200 bg-white p-1 shadow-lg">
                                    <a class="rounded px-2 py-1.5 text-sm font-semibold text-teal-700 hover:bg-zinc-50" href="{{ route('bd-commissions.documents.download', ['period' => $detail['period']->id, 'bdUserId' => $exportBdId, 'format' => 'xlsx']) }}">Excel</a>
                                    <a class="rounded px-2 py-1.5 text-sm font-semibold text-teal-700 hover:bg-zinc-50" href="{{ route('bd-commissions.documents.download', ['period' => $detail['period']->id, 'bdUserId' => $exportBdId, 'format' => 'pdf']) }}">PDF</a>
                                </div>
                            </details>
                        @endif
                    @endif
                    <span class="rounded-full bg-zinc-100 px-3 py-1 text-sm">{{ __('settlements.bd_commission.statuses.'.$detail['period']->status) }}</span>
                    <flux:button size="sm" variant="ghost" wire:click="$set('selectedPeriodId', null)">{{ __('settlements.bd_commission.collapse') }}</flux:button>
                </div>
            </div>
            <div class="mt-4 overflow-x-auto"><table class="min-w-full text-left text-sm"><thead><tr class="border-b text-zinc-500"><th class="p-2">{{ __('settlements.bd_commission.order') }}</th><th class="p-2">{{ __('settlements.bd_commission.bd') }}</th><th class="p-2">{{ __('settlements.bd_commission.occurred_on') }}</th><th class="p-2">{{ __('settlements.bd_commission.basis') }}</th><th class="p-2">{{ __('settlements.bd_commission.rate') }}</th><th class="p-2">{{ __('settlements.bd_commission.commission') }}</th></tr></thead><tbody>
                @forelse ($detail['items'] as $item)<tr class="border-b"><td class="p-2">#{{ $item['order_id'] }}</td><td class="p-2">{{ $item['bd_name'] }}</td><td class="p-2">{{ $item['occurred_on'] }}</td><td class="p-2">₩ {{ number_format($item['basis_krw']) }}</td><td class="p-2">{{ number_format($item['rate_bps'] / 100, 2) }}%</td><td class="p-2">₩ {{ number_format($item['commission_krw']) }}</td></tr>@empty<tr><td colspan="6" class="p-4 text-center text-zinc-500">{{ __('settlements.bd_commission.empty_items') }}</td></tr>@endforelse
            </tbody></table></div>
            @if (auth()->user()->is_super_admin && $detail['period']->status !== 'confirmed')
                <div class="mt-5 grid gap-3 md:grid-cols-4"><flux:select wire:model="adjustmentBdUserId" label="{{ __('settlements.bd_commission.bd') }}"><option value="">{{ __('settlements.bd_commission.allocation') }}</option>@foreach ($users as $user)<option value="{{ $user['id'] }}">{{ $user['name'] }}</option>@endforeach</flux:select><flux:input type="number" wire:model="adjustmentAmountKrw" label="{{ __('settlements.bd_commission.adjustment_amount') }}" /><flux:input wire:model="adjustmentReason" label="{{ __('settlements.bd_commission.reason') }}" /><div class="flex items-end"><flux:button wire:click="addAdjustment" variant="primary" class="w-auto">{{ __('settlements.bd_commission.add_adjustment') }}</flux:button></div></div>
            @endif
            @if (auth()->user()->is_super_admin)
                <div class="mt-4 flex flex-wrap justify-end gap-2">@if ($detail['period']->status === 'generated')<flux:button wire:click="submitReview">{{ __('settlements.bd_commission.review') }}</flux:button>@endif @if ($detail['period']->status === 'reviewed')<flux:button wire:click="confirm" variant="primary">{{ __('settlements.bd_commission.confirm') }}</flux:button>@endif</div>
            @endif
        </section>
    @endif

    @if (auth()->user()->is_super_admin)
        <section class="rounded-xl border bg-white p-5">
            <h2 class="text-lg font-semibold">{{ __('settlements.bd_commission.rules') }}</h2>
            <p class="mt-1 text-sm text-zinc-600">{{ __('settlements.bd_commission.rule_assumption') }}</p>
            <div class="mt-4 grid gap-3 md:grid-cols-12 md:items-end">
                <div class="md:col-span-3"><flux:input class="w-full" type="date" wire:model="ruleEffectiveFrom" label="{{ __('settlements.bd_commission.effective_from') }}" /></div>
                <div class="md:col-span-3"><flux:input class="w-full" type="number" wire:model="ruleRateBps" label="{{ __('settlements.bd_commission.rate_bps') }}" /></div>
                <div class="md:col-span-4"><flux:input class="w-full" wire:model="ruleReason" label="{{ __('settlements.bd_commission.reason') }}" /></div>
                <div class="flex items-end md:col-span-2"><flux:button class="w-auto whitespace-nowrap" wire:click="saveRule" variant="primary">{{ __('settlements.bd_commission.save_rule') }}</flux:button></div>
            </div>
            <ul class="mt-4 space-y-1 text-sm">@foreach ($rules as $rule)<li>{{ $rule->effective_from->format('Y-m-d') }} · {{ number_format($rule->rate_bps / 100, 2) }}% · {{ $rule->reason }}</li>@endforeach</ul>
        </section>
    @endif
</div>
