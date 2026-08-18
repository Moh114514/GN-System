<div>
    <x-page-back :href="route('customers.index')" :label="__('customers.detail.back')" class="mb-4" />

    <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
        <div class="space-y-6">
            <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="text-lg font-semibold">{{ __('customers.detail.profile.heading') }}</h2>
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="crm-pill tone-blue">{{ $customer['current_status'] }}</span>
                        <flux:button :href="route('reminders.create', ['customer' => $customer['id']])" size="sm" icon="bell-alert" wire:navigate>{{ __('customers.detail.actions.add_followup_reminder') }}</flux:button>
                        <flux:button :href="route('customers.orders', $customer['id'])" size="sm" icon="banknotes" wire:navigate>{{ __('customers.detail.actions.register_order') }}</flux:button>
                        <flux:button :href="route('customers.edit', $customer['id'])" size="sm" icon="pencil-square" wire:navigate>{{ __('customers.detail.actions.edit_profile') }}</flux:button>
                    </div>
                </div>
                <dl class="mt-5 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    <div><dt class="text-xs text-zinc-500">{{ __('customers.detail.profile.name') }}</dt><dd class="mt-1 font-semibold">{{ $customer['name'] }}</dd></div>
                    <div><dt class="text-xs text-zinc-500">{{ __('customers.detail.profile.code') }}</dt><dd class="mt-1 font-medium">{{ $customer['code'] }}</dd></div>
                    <div><dt class="text-xs text-zinc-500">{{ __('customers.detail.profile.contact') }}</dt><dd class="mt-1 font-medium">{{ $customer['contact'] }}</dd></div>
                    <div><dt class="text-xs text-zinc-500">{{ __('customers.detail.profile.identity_document') }}</dt><dd class="mt-1 font-medium">{{ $customer['identity_document'] }}</dd></div>
                    <div><dt class="text-xs text-zinc-500">{{ __('customers.detail.profile.birth_date') }}</dt><dd class="mt-1 font-medium">{{ $customer['birth_date'] }}</dd></div>
                    <div><dt class="text-xs text-zinc-500">{{ __('customers.detail.profile.source_type') }}</dt><dd class="mt-1 font-medium">{{ $customer['source_agent_name'] ?? __('customers.fallback.unknown_agent') }}</dd></div>
                    <div><dt class="text-xs text-zinc-500">{{ __('customers.detail.profile.project_intention') }}</dt><dd class="mt-1 font-medium">{{ $customer['project_intention'] }}</dd></div>
                    <div><dt class="text-xs text-zinc-500">{{ __('customers.detail.profile.created_at') }}</dt><dd class="mt-1 font-medium">{{ $customer['created_at'] }}</dd></div>
                    <div><dt class="text-xs text-zinc-500">{{ __('customers.detail.profile.notes') }}</dt><dd class="mt-1 font-medium">{{ $customer['notes'] ?: __('customers.detail.profile.empty_notes') }}</dd></div>
                </dl>
            </section>

            <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900" data-test="customer-status-flow">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h3 class="text-lg font-semibold">{{ __('customers.detail.status_flow.heading') }}</h3>
                        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ __('customers.detail.status_flow.description') }}</p>
                    </div>
                    <span class="crm-pill tone-blue">{{ __('customers.detail.status_flow.current') }}: {{ $customer['current_status'] ?: __('customers.fallback.unset') }}</span>
                </div>

                <div class="mt-4 flex flex-wrap gap-2 text-xs text-zinc-600 dark:text-zinc-300" aria-label="{{ __('customers.detail.status_flow.legend') }}">
                    <span class="inline-flex items-center gap-1 rounded-full bg-teal-50 px-2.5 py-1 text-teal-800 dark:bg-teal-950/40 dark:text-teal-100"><span aria-hidden="true">●</span>{{ __('customers.detail.status_flow.states.current') }}</span>
                    <span class="inline-flex items-center gap-1 rounded-full bg-zinc-100 px-2.5 py-1 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300"><span aria-hidden="true">✓</span>{{ __('customers.detail.status_flow.states.completed') }}</span>
                    <span class="inline-flex items-center gap-1 rounded-full bg-blue-50 px-2.5 py-1 text-blue-800 dark:bg-blue-950/40 dark:text-blue-100"><span aria-hidden="true">○</span>{{ __('customers.detail.status_flow.states.available') }}</span>
                    <span class="inline-flex items-center gap-1 rounded-full bg-zinc-100 px-2.5 py-1 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400"><span aria-hidden="true">—</span>{{ __('customers.detail.status_flow.states.unavailable') }}</span>
                </div>

                @php
                    $flowStatuses = collect($statusFlow['statuses']);
                    $flowTransitions = collect($statusFlow['transitions']);
                    $completedStatus = $flowStatuses->firstWhere('key', 'treatment_completed');
                @endphp
                @if ($flowStatuses->isNotEmpty())
                    <div class="mt-6 overflow-x-auto pb-2">
                        <ol class="flex min-w-[42rem] items-start" data-status-stepper>
                            @foreach ($flowStatuses as $status)
                                @php
                                    $statusClasses = match ($status['state']) {
                                        'current' => 'border-teal-400 bg-teal-50 text-teal-950 ring-2 ring-teal-200 dark:border-teal-500 dark:bg-teal-950/40 dark:text-teal-100 dark:ring-teal-800',
                                        'current_inactive' => 'border-amber-400 bg-amber-50 text-amber-950 ring-2 ring-amber-200 dark:border-amber-500 dark:bg-amber-950/40 dark:text-amber-100 dark:ring-amber-800',
                                        'available' => 'border-blue-300 bg-blue-50 text-blue-950 dark:border-blue-500 dark:bg-blue-950/30 dark:text-blue-100',
                                        'completed' => 'border-zinc-200 bg-white text-zinc-600 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300',
                                        default => 'border-zinc-200 bg-white text-zinc-400 opacity-60 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-500',
                                    };
                                    $statusMarker = match ($status['state']) {
                                        'current', 'current_inactive' => '●',
                                        'completed' => '✓',
                                        'available' => '○',
                                        default => '—',
                                    };
                                    $nextStatus = $flowStatuses->get($loop->index + 1);
                                    $transition = $nextStatus === null ? null : $flowTransitions->first(fn (array $candidate): bool => $candidate['from_status_id'] === $status['id'] && $candidate['to_status_id'] === $nextStatus['id']);
                                @endphp
                                <li class="flex min-w-0 flex-1 items-start" data-status-key="{{ $status['key'] }}" data-status-state="{{ $status['state'] }}" data-status-visited="{{ $status['is_visited'] ? 'true' : 'false' }}" data-status-current="{{ $status['is_current'] ? 'true' : 'false' }}">
                                    <div class="min-w-0 flex-1">
                                        <div class="flex h-10 w-10 items-center justify-center rounded-full border text-lg font-semibold {{ $statusClasses }}" aria-hidden="true">{{ $statusMarker }}</div>
                                        <p class="mt-2 truncate font-semibold">{{ $status['name'] }}</p>
                                        <p class="mt-1 text-xs text-zinc-500">{{ __('customers.detail.status_flow.states.'.$status['state']) }}</p>
                                    </div>
                                    @if ($nextStatus !== null)
                                        <div class="flex flex-1 items-center px-3 pt-5" data-transition="{{ $transition['from_status_id'] ?? $status['id'] }}-{{ $transition['to_status_id'] ?? $nextStatus['id'] }}" data-transition-visited="{{ $transition && $transition['visited'] ? 'true' : 'false' }}">
                                            <span class="h-0.5 flex-1 {{ $transition && $transition['visited'] ? 'bg-teal-400' : 'bg-zinc-200 dark:bg-zinc-700' }}"></span>
                                            <span class="ml-2 text-zinc-400" aria-hidden="true">→</span>
                                        </div>
                                    @endif
                                </li>
                            @endforeach
                        </ol>
                    </div>
                @else
                    <p class="py-8 text-center text-zinc-500">{{ __('customers.detail.status_flow.empty') }}</p>
                @endif
                @if ($completedStatus && in_array($completedStatus['state'], ['current', 'current_inactive', 'completed'], true))
                    <div class="mt-4 rounded-xl border border-teal-200 bg-teal-50/70 px-4 py-3 text-sm text-teal-900 dark:border-teal-900 dark:bg-teal-950/30 dark:text-teal-100" data-post-treatment-reminders>
                        <p class="font-semibold">{{ __('customers.detail.status_flow.post_treatment_reminders') }}</p>
                        <p class="mt-1">{{ __('customers.detail.status_flow.post_treatment_7') }} · {{ __('customers.detail.status_flow.post_treatment_30') }}</p>
                    </div>
                @endif
            </section>

            <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h3 class="text-lg font-semibold">{{ __('customers.detail.timeline.heading') }}</h3>
                    <flux:select wire:model.live="timelineType" class="w-48">
                        <flux:select.option value="">{{ __('customers.detail.timeline.all') }}</flux:select.option>
                        <flux:select.option value="created">{{ __('customers.detail.timeline.created') }}</flux:select.option>
                        <flux:select.option value="appointment">{{ __('customers.detail.timeline.appointment') }}</flux:select.option>
                        <flux:select.option value="order">{{ __('customers.detail.timeline.order') }}</flux:select.option>
                        <flux:select.option value="followup">{{ __('customers.detail.timeline.followup') }}</flux:select.option>
                        <flux:select.option value="status">{{ __('customers.detail.timeline.status') }}</flux:select.option>
                        <flux:select.option value="profile">{{ __('customers.detail.timeline.profile') }}</flux:select.option>
                    </flux:select>
                </div>
                <div class="mt-6 space-y-5">
                    @forelse ($timeline as $event)
                        <article class="relative border-l-2 border-teal-200 pl-5" wire:key="{{ $event['type'] }}-{{ $loop->index }}-{{ $event['occurred_at'] }}">
                            <div class="flex flex-wrap justify-between gap-2">
                                <h4 class="font-semibold">{{ $event['title'] }}</h4>
                                <time class="text-xs text-zinc-500">{{ \Carbon\CarbonImmutable::parse($event['occurred_at'])->format('Y-m-d H:i') }}</time>
                            </div>
                            <p class="mt-1 text-sm text-zinc-600">{{ $event['content'] }}</p>
                            <p class="mt-1 text-xs text-zinc-400">
                                {{ $event['institution'] ?? '' }}
                                @if ($event['owner'])
                                    <span>· <strong class="font-semibold">{{ $event['owner'] }}</strong></span>
                                @endif
                            </p>
                        </article>
                    @empty
                        <p class="py-8 text-center text-zinc-500">{{ __('customers.detail.timeline.empty') }}</p>
                    @endforelse
                </div>
            </section>
        </div>

        <aside class="space-y-6">
            <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <h3 class="font-semibold">{{ __('customers.detail.status.heading') }}</h3>
                <form wire:submit="changeStatus" class="mt-4 space-y-3">
                    <flux:select wire:model="targetStatusId" :label="__('customers.detail.status.target')" required>
                        <flux:select.option value="">{{ __('customers.form.select') }}</flux:select.option>
                        @foreach ($options['statuses'] as $status)
                            <flux:select.option value="{{ $status['id'] }}">{{ $status['name'] }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:textarea wire:model="statusReason" :label="__('customers.detail.status.reason')" rows="3" required />
                    <flux:button type="submit" variant="primary" class="w-full">{{ __('customers.detail.status.submit') }}</flux:button>
                </form>
            </section>
            <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <h3 class="font-semibold">{{ __('customers.detail.followup.heading') }}</h3>
                <form wire:submit="recordFollowup" class="mt-4 space-y-3">
                    <flux:input wire:model="followupType" :label="__('customers.detail.followup.type')" required />
                    <x-localized-date-picker wire:model="followedUpOn" :value="$followedUpOn" :label="__('customers.detail.followup.date')" required />
                    <flux:textarea wire:model="followupContent" :label="__('customers.detail.followup.content')" rows="4" required />
                    <flux:button type="submit" class="w-full">{{ __('customers.detail.followup.submit') }}</flux:button>
                </form>
            </section>
        </aside>
    </div>
</div>
