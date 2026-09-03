<div>
    <x-page-back :href="route('orders.index')" :label="__('orders.detail.back')" class="mb-4" />

    @php
        $deleted = $order['deleted_at'] !== null;
        $statusLabels = ['pending' => __('orders.statuses.pending'), 'completed' => __('orders.statuses.completed'), 'cancelled' => __('orders.statuses.cancelled')];
        $statusTone = ['pending' => 'tone-amber', 'completed' => 'tone-green', 'cancelled' => 'tone-red'];
        $isAdmin = (bool) auth()->user()?->is_super_admin;
    @endphp

    <section class="crm-section-header">
        <div>
            <p class="text-xs font-medium text-zinc-400">{{ __('orders.detail.eyebrow', ['id' => $order['id']]) }}</p>
            <h2 class="mt-1 text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-50">{{ $order['project_name'] }}</h2>
            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ __('orders.detail.description') }}</p>
        </div>
        <span class="crm-pill {{ $deleted ? 'tone-red' : ($statusTone[$order['status']] ?? 'tone-blue') }}">{{ $deleted ? __('orders.detail.deleted') : ($statusLabels[$order['status']] ?? $order['status']) }}</span>
    </section>


    <section id="status-editor" class="crm-card mb-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h3 class="font-semibold">{{ __('orders.detail.edit_status') }}</h3>
                <p class="mt-1 text-sm text-zinc-500">{{ __('orders.detail.status_description') }}</p>
            </div>
            @if ($deleted)
                <span class="text-sm text-zinc-500">{{ __('orders.detail.deleted_hint') }}</span>
            @elseif ($order['status'] === 'completed' && ! $isAdmin)
                <span class="text-sm text-zinc-500">{{ __('orders.detail.completed_hint') }}</span>
            @elseif ($order['status'] === 'pending' || $isAdmin)
                <div class="flex flex-wrap items-end gap-2">
                    <flux:select wire:model.live="statusSelection" :label="__('orders.fields.status')" class="min-w-48">
                        @if ($order['status'] === 'pending')
                            <flux:select.option value="pending">{{ __('orders.statuses.pending') }}</flux:select.option>
                            @if ($isAdmin)<flux:select.option value="cancelled">{{ __('orders.statuses.cancelled') }}</flux:select.option>@endif
                        @elseif ($order['status'] === 'completed' && $isAdmin)
                            <flux:select.option value="completed">{{ __('orders.statuses.completed') }}</flux:select.option>
                            <flux:select.option value="pending">{{ __('orders.statuses.pending') }}</flux:select.option>
                        @elseif ($order['status'] === 'cancelled' && $isAdmin)
                            <flux:select.option value="cancelled">{{ __('orders.statuses.cancelled') }}</flux:select.option>
                            <flux:select.option value="pending">{{ __('orders.statuses.pending') }}</flux:select.option>
                        @endif
                    </flux:select>
                    <flux:button wire:click="changeStatus" variant="primary">{{ __('orders.detail.save_status') }}</flux:button>
                </div>
            @endif
        </div>
        @if (! $deleted && $isAdmin && in_array($statusSelection, ['cancelled', 'pending'], true) && $statusSelection !== $order['status'])
            <div class="mt-4 max-w-xl">
                <flux:textarea wire:model="reason" :label="__('orders.detail.status_reason')" rows="2" />
            </div>
        @endif
        @error('statusSelection') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
    </section>

    <div class="grid gap-6 xl:grid-cols-[minmax(0,1.35fr)_minmax(18rem,0.65fr)]">
        <div class="space-y-6">
            <section class="crm-card">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div><h3 class="font-semibold">{{ __('orders.detail.order_info') }}</h3><p class="mt-1 text-sm text-zinc-500">{{ __('orders.detail.order_info_description') }}</p></div>
                    <div class="flex flex-wrap gap-2">
                        @if (! $deleted && ($order['can_edit'] ?? false))
                            <flux:button href="{{ route('orders.edit', $order['id']) }}" wire:navigate variant="ghost" size="sm">{{ __('orders.detail.edit') }}</flux:button>
                            @if ($order['status'] === 'pending')<span class="text-xs text-zinc-500">{{ __('orders.detail.awaiting_institution_return') }}</span>@endif
                        @endif
                    </div>
                </div>
                <dl class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div><dt class="text-xs text-zinc-500">{{ __('orders.fields.customer') }}</dt><dd class="mt-1 font-semibold"><a class="text-teal-700 hover:underline" href="{{ route('customers.show', $order['customer']['id']) }}" wire:navigate>{{ $order['customer']['name'] }}</a><span class="ml-1 text-xs font-normal text-zinc-500">{{ $order['customer']['code'] }}</span></dd></div>
                    <div><dt class="text-xs text-zinc-500">{{ __('orders.fields.institution') }}</dt><dd class="mt-1 font-medium">{{ $order['institution']['name'] ?? __('orders.values.unknown_institution') }}</dd></div>
                    <div><dt class="text-xs text-zinc-500">{{ __('orders.fields.agent') }}</dt><dd class="mt-1 font-medium">{{ $order['agent']['name'] ?? __('orders.values.unknown_agent') }}</dd></div>
                    <div><dt class="text-xs text-zinc-500">{{ __('orders.fields.transaction_amount') }}</dt><dd class="mt-1 font-semibold">₩ {{ number_format($order['amount_krw']) }}</dd></div>
                    <div><dt class="text-xs text-zinc-500">{{ __('orders.fields.occurred_on') }}</dt><dd class="mt-1">{{ $order['occurred_on'] ?? __('orders.values.empty') }}</dd></div>
                    <div><dt class="text-xs text-zinc-500">{{ __('orders.fields.completed_time') }}</dt><dd class="mt-1">{{ $order['completed_at'] ?? __('orders.values.empty') }}</dd></div>
                    <div><dt class="text-xs text-zinc-500">{{ __('orders.fields.created_at') }}</dt><dd class="mt-1">{{ $order['created_at'] ?? __('orders.values.empty') }}</dd></div>
                    <div><dt class="text-xs text-zinc-500">{{ __('orders.fields.translation') }}</dt><dd class="mt-1">{{ $order['translator_name'] ?: __('orders.values.empty') }}{{ $order['translator_language'] ? ' · '.$order['translator_language'] : '' }}</dd></div>
                    <div class="sm:col-span-2"><dt class="text-xs text-zinc-500">{{ __('orders.fields.notes') }}</dt><dd class="mt-1 whitespace-pre-wrap">{{ $order['notes'] ?: __('orders.values.empty') }}</dd></div>
                </dl>
            </section>

            @if ($order['status'] === 'completed')
                <section class="crm-card">
                    <h3 class="font-semibold">{{ __('orders.detail.promotion_and_settlement') }}</h3>
                    @if ($order['financial']['commission'])
                        <dl class="mt-4 grid gap-4 sm:grid-cols-3">
                            <div><dt class="text-xs text-zinc-500">{{ __('orders.fields.promotion_fee') }}</dt><dd class="mt-1 font-semibold">₩ {{ number_format($order['financial']['commission']['amount_krw']) }}</dd></div>
                            <div><dt class="text-xs text-zinc-500">{{ __('orders.fields.commission_rate') }}</dt><dd class="mt-1">{{ number_format($order['financial']['commission']['rate_bps'] / 100, 2) }}%</dd></div>
                            <div><dt class="text-xs text-zinc-500">{{ __('orders.fields.settlement_status') }}</dt><dd class="mt-1">{{ $order['financial']['settlement'] ? $order['financial']['settlement']['status'] : __('orders.detail.settlement_pending') }}</dd></div>
                        </dl>
                        <p class="mt-4 text-xs text-zinc-500">{{ __('orders.detail.promotion_snapshot_description') }}</p>
                    @else
                        <p class="mt-4 text-sm text-zinc-500">{{ __('orders.detail.no_promotion_snapshot') }}</p>
                    @endif
                </section>
            @endif

            <section class="crm-card">
                <h3 class="font-semibold">{{ __('orders.detail.related_reminders') }}</h3>
                @forelse ($order['reminders'] as $reminder)
                    <div class="mt-4 flex flex-wrap items-start justify-between gap-3 border-b border-zinc-100 pb-3 last:border-0 last:pb-0 dark:border-zinc-800">
                        <div><p class="font-medium">{{ $reminder['title'] }}</p><p class="mt-1 text-sm text-zinc-500">{{ $reminder['due_at'] }} · {{ $reminder['notes'] ?: __('orders.values.no_note') }}</p></div>
                        <span class="crm-pill {{ $reminder['status'] === 'completed' ? 'tone-green' : 'tone-amber' }}">{{ $reminder['status'] === 'completed' ? __('orders.statuses.reminder_completed') : __('orders.statuses.reminder_pending') }}</span>
                    </div>
                @empty
                    <p class="mt-4 text-sm text-zinc-500">{{ __('orders.detail.no_reminders') }}</p>
                @endforelse
            </section>
        </div>

        <aside class="space-y-6">
            @if ($isAdmin && ! $deleted && $order['status'] === 'cancelled')
                <section class="rounded-2xl border border-amber-300 bg-amber-50 p-5 dark:bg-amber-950/30">
                    <h3 class="font-semibold text-amber-900 dark:text-amber-200">{{ __('orders.detail.operations') }}</h3>
                    <p class="mt-1 text-sm text-amber-800 dark:text-amber-300">{{ __('orders.detail.operation_description') }}</p>
                    <flux:textarea wire:model="reason" :label="__('orders.detail.operation_reason')" rows="3" class="mt-4" />
                    <div class="mt-4 flex flex-wrap gap-2">
                        <flux:button wire:click="reopen" variant="primary" wire:confirm="{{ __('orders.detail.reopen_confirm') }}">{{ __('orders.detail.reopen') }}</flux:button>
                        <flux:button wire:click="softDelete" variant="danger" wire:confirm="{{ __('orders.detail.soft_delete_confirm') }}">{{ __('orders.detail.soft_delete') }}</flux:button>
                    </div>
                </section>
            @elseif ($isAdmin && $deleted)
                <section class="rounded-2xl border border-amber-300 bg-amber-50 p-5 dark:bg-amber-950/30">
                    <h3 class="font-semibold text-amber-900 dark:text-amber-200">{{ __('orders.detail.recycle_order') }}</h3>
                    <p class="mt-1 text-sm text-amber-800 dark:text-amber-300">{{ __('orders.detail.recycle_description') }}</p>
                    <flux:button wire:click="restore" class="mt-4" variant="primary" wire:confirm="{{ __('orders.detail.restore_confirm') }}">{{ __('orders.detail.restore') }}</flux:button>
                </section>
            @elseif ($order['status'] === 'cancelled')
                <section class="rounded-2xl border border-zinc-200 bg-zinc-50 p-5 dark:border-zinc-700 dark:bg-zinc-800/50"><h3 class="font-semibold">{{ __('orders.detail.cancel_info') }}</h3><p class="mt-2 text-sm text-zinc-600 dark:text-zinc-300">{{ $order['cancellation_reason'] ?: __('orders.detail.no_reason') }}</p></section>
            @endif

            <section class="crm-card">
                <h3 class="font-semibold">{{ __('orders.detail.audit') }}</h3>
                <div class="mt-4 space-y-4">
                    @forelse ($order['audit'] as $entry)
                        <div class="border-l-2 border-teal-200 pl-3 dark:border-teal-800"><p class="font-medium">{{ $entry['description'] }}</p><p class="mt-1 text-xs text-zinc-500">{{ $entry['occurred_at'] }} · {{ __('orders.values.system') }} #{{ $entry['causer_id'] ?? '' }}</p>@if (isset($entry['properties']['reason']))<p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">{{ __('orders.detail.audit_reason', ['reason' => $entry['properties']['reason']]) }}</p>@endif</div>
                    @empty
                        <p class="text-sm text-zinc-500">{{ __('orders.detail.no_audit') }}</p>
                    @endforelse
                </div>
            </section>
        </aside>
    </div>
</div>
