<div>
    <section class="crm-section-header">
        <div><h2 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-50">{{ __('reminders.titles.center') }}</h2><p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ __('reminders.center.description') }}</p></div>
        <div class="flex gap-2"><flux:button :href="route('reminders.history')" variant="ghost" wire:navigate>{{ __('reminders.center.history') }}</flux:button><flux:button :href="route('reminders.create')" icon="plus" variant="primary" wire:navigate>{{ __('reminders.center.create') }}</flux:button></div>
    </section>

    @if ($stats)
        <section class="mb-6 grid gap-3 sm:grid-cols-3"><div class="rounded-xl border bg-white p-4 dark:bg-zinc-900"><span class="text-sm text-zinc-500">{{ __('reminders.center.pending') }}</span><strong class="mt-1 block text-2xl">{{ $stats['pending'] }}</strong></div><div class="rounded-xl border bg-white p-4 dark:bg-zinc-900"><span class="text-sm text-zinc-500">{{ __('reminders.center.overdue') }}</span><strong class="mt-1 block text-2xl text-red-600">{{ $stats['overdue'] }}</strong></div><div class="rounded-xl border bg-white p-4 dark:bg-zinc-900"><span class="text-sm text-zinc-500">{{ __('reminders.center.completed') }}</span><strong class="mt-1 block text-2xl">{{ $stats['completed'] }}</strong></div></section>
    @endif

    <div class="space-y-4">
        <section class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="w-full max-w-xs"><flux:select wire:model.live="type" :label="__('reminders.center.type')"><flux:select.option value="">{{ __('reminders.center.all_types') }}</flux:select.option><flux:select.option value="appointment">{{ __('reminders.center.appointment') }}</flux:select.option><flux:select.option value="post_treatment">{{ __('reminders.center.post_treatment') }}</flux:select.option><flux:select.option value="date_offset">{{ __('reminders.center.date_offset') }}</flux:select.option><flux:select.option value="fixed_cycle">{{ __('reminders.center.fixed_cycle') }}</flux:select.option><flux:select.option value="custom">{{ __('reminders.center.custom') }}</flux:select></flux:select></div>
        </section>

        <section data-test="reminder-list" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-5">
            @forelse ($reminders as $reminder)
                <article wire:key="reminder-{{ $reminder->id }}" data-test="reminder-card" class="flex h-[24.5rem] flex-col rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm transition-colors hover:border-zinc-300 dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-zinc-600">
                    <div class="min-h-0 flex-1">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                <h3 class="text-base font-semibold leading-6">{{ $reminder->title }}</h3>
                                <span class="crm-pill {{ $reminder->due_at->isPast() ? 'tone-red' : 'tone-amber' }}">{{ $reminder->due_at->isPast() ? __('reminders.center.due') : __('reminders.center.pending') }}</span>
                            </div>
                            <p class="mt-2 flex flex-wrap items-center gap-x-2 text-sm text-zinc-600 dark:text-zinc-300">
                                @if (isset($customerNames[$reminder->customer_id]))
                                    <a class="font-semibold text-teal-700 underline-offset-2 hover:underline dark:text-teal-300" href="{{ route('customers.show', $reminder->customer_id) }}" wire:navigate>{{ $customerNames[$reminder->customer_id] }}</a>
                                @else
                                    <span class="font-semibold">{{ __('reminders.center.unknown_customer') }}</span>
                                @endif
                                <span aria-hidden="true">·</span>
                                <time datetime="{{ $reminder->due_at->toIso8601String() }}">{{ $reminder->due_at->format('Y-m-d H:i') }}</time>
                            </p>
                            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ $reminder->suggestion ?: __('reminders.center.no_script') }}</p>
                        </div>
                    </div>

                    <div class="mt-4 flex shrink-0 flex-wrap items-center gap-1.5">
                        <flux:button wire:click="openAction({{ $reminder->id }}, 'complete')" size="sm">{{ __('reminders.center.mark_complete') }}</flux:button>
                        <flux:button wire:click="openAction({{ $reminder->id }}, 'snooze')" size="sm" variant="ghost">{{ __('reminders.center.snooze') }}</flux:button>
                        <flux:button wire:click="openAction({{ $reminder->id }}, 'transfer')" size="sm" variant="ghost">{{ __('reminders.center.transfer') }}</flux:button>
                        <flux:button wire:click="openAction({{ $reminder->id }}, 'cancel')" size="sm" variant="ghost">{{ __('reminders.center.cancel') }}</flux:button>
                        @if (in_array($reminder->notification_status, ['failed', 'disabled'], true))
                            <flux:button wire:click="retryNotification({{ $reminder->id }})" size="sm" variant="ghost">{{ __('reminders.center.retry_notification') }}</flux:button>
                        @endif
                    </div>

                    @if ($activeReminderId === $reminder->id)
                        <div class="mt-3 rounded-lg border border-teal-200 bg-teal-50/50 p-3 dark:border-teal-900 dark:bg-teal-950/20" data-test="reminder-action-form">
                            @if (in_array($actionMode, ['complete', 'cancel'], true))
                                <flux:input wire:model="actionNotes" :label="__('reminders.center.complete_notes')" />
                            @elseif ($actionMode === 'snooze')
                                <div class="grid gap-3 sm:max-w-2xl sm:grid-cols-2">
                                    <flux:input wire:model="snoozeUntil" type="datetime-local" :label="__('reminders.center.snooze_until')" />
                                    <flux:input wire:model="snoozeReason" :label="__('reminders.center.snooze_reason')" />
                                </div>
                            @elseif ($actionMode === 'transfer')
                                <div class="max-w-md">
                                    <flux:select wire:model="assigneeId" :label="__('reminders.center.transfer_to')">
                                        <flux:select.option value="">{{ __('reminders.center.select') }}</flux:select.option>
                                        @foreach ($users as $user)
                                            <flux:select.option value="{{ $user->id }}">{{ $user->name }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                </div>
                            @endif

                            <div class="mt-3 flex flex-wrap gap-2">
                                @if ($actionMode === 'complete')
                                    <flux:button wire:click="complete({{ $reminder->id }})" size="sm">{{ __('reminders.center.mark_complete') }}</flux:button>
                                @elseif ($actionMode === 'snooze')
                                    <flux:button wire:click="snooze({{ $reminder->id }})" size="sm">{{ __('reminders.center.snooze') }}</flux:button>
                                @elseif ($actionMode === 'transfer')
                                    <flux:button wire:click="transfer({{ $reminder->id }})" size="sm">{{ __('reminders.center.transfer') }}</flux:button>
                                @elseif ($actionMode === 'cancel')
                                    <flux:button wire:click="cancel({{ $reminder->id }})" size="sm" variant="danger">{{ __('reminders.center.cancel') }}</flux:button>
                                @endif
                                <flux:button wire:click="closeAction" size="sm" variant="ghost">{{ __('reminders.center.cancel_action') }}</flux:button>
                            </div>
                        </div>
                    @endif
                </article>
            @empty<p class="py-10 text-center text-zinc-500">{{ __('reminders.center.empty') }}</p>@endforelse
        </section>
        <div>{{ $reminders->links() }}</div>
    </div>
</div>
