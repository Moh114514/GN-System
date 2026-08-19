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

                <div class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-1 text-[11px] text-zinc-400 dark:text-zinc-500" aria-label="{{ __('customers.detail.status_flow.legend') }}">
                    <span class="inline-flex items-center gap-1.5"><span class="h-1.5 w-1.5 rounded-full bg-teal-500" aria-hidden="true"></span>{{ __('customers.detail.status_flow.states.current') }}</span>
                    <span class="inline-flex items-center gap-1.5"><span class="text-teal-600 dark:text-teal-400" aria-hidden="true">✓</span>{{ __('customers.detail.status_flow.states.completed') }}</span>
                </div>

                @php
                    $flowStatuses = collect($statusFlow['statuses']);
                    $flowTransitions = collect($statusFlow['transitions']);
                    $completedStatus = $flowStatuses->firstWhere('key', 'treatment_completed');
                @endphp
                @if ($flowStatuses->isNotEmpty())
                    <div class="mt-6 w-full">
                        <ol class="flex w-full items-start" data-status-stepper>
                            @foreach ($flowStatuses as $status)
                                @php
                                    $statusNodeClasses = match ($status['state']) {
                                        'current' => 'h-6 w-6 border-2 border-teal-500 bg-white text-teal-600 ring-2 ring-teal-100 dark:border-teal-400 dark:bg-teal-950/30 dark:text-teal-300 dark:ring-teal-900/50',
                                        'current_inactive' => 'h-6 w-6 border-2 border-amber-400 bg-white text-amber-600 ring-2 ring-amber-100 dark:border-amber-400 dark:bg-amber-950/30 dark:text-amber-300 dark:ring-amber-900/50',
                                        'completed' => 'h-5 w-5 border border-teal-300 bg-teal-50 text-teal-700 dark:border-teal-600 dark:bg-teal-950/30 dark:text-teal-300',
                                        'available' => 'h-5 w-5 border border-zinc-300 bg-white text-zinc-400 dark:border-zinc-600 dark:bg-zinc-900 dark:text-zinc-500',
                                        default => 'h-5 w-5 border border-zinc-200 bg-zinc-50 text-zinc-300 opacity-70 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-600',
                                    };
                                    $nextStatus = $flowStatuses->get($loop->index + 1);
                                    $transition = $nextStatus === null ? null : $flowTransitions->first(fn (array $candidate): bool => $candidate['from_status_id'] === $status['id'] && $candidate['to_status_id'] === $nextStatus['id']);
                                @endphp
                                <li class="min-w-0 flex-1 text-center" data-status-key="{{ $status['key'] }}" data-status-state="{{ $status['state'] }}" data-status-visited="{{ $status['is_visited'] ? 'true' : 'false' }}" data-status-current="{{ $status['is_current'] ? 'true' : 'false' }}">
                                    <div class="flex h-6 items-center justify-center">
                                        <span class="inline-flex items-center justify-center rounded-full font-semibold {{ $statusNodeClasses }}" aria-hidden="true">
                                            @if (in_array($status['state'], ['current', 'current_inactive'], true))
                                                <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
                                            @elseif ($status['state'] === 'completed')
                                                <span class="text-[11px] leading-none">✓</span>
                                            @else
                                                <span class="h-1.5 w-1.5 rounded-full border border-current"></span>
                                            @endif
                                        </span>
                                    </div>
                                    <p class="mt-2 truncate text-sm font-semibold text-zinc-900 dark:text-zinc-100">{{ $status['name'] }}</p>
                                    <p class="mt-1 text-[11px] leading-4 text-zinc-400 dark:text-zinc-500">{{ __('customers.detail.status_flow.states.'.$status['state']) }}</p>
                                </li>
                                    @if ($nextStatus !== null)
                                    <li class="flex min-w-0 flex-1 items-center px-1 pt-3 sm:px-3" data-transition="{{ $transition['from_status_id'] ?? $status['id'] }}-{{ $transition['to_status_id'] ?? $nextStatus['id'] }}" data-transition-visited="{{ $transition && $transition['visited'] ? 'true' : 'false' }}">
                                        <svg class="h-2 flex-1" viewBox="0 0 100 10" preserveAspectRatio="none" fill="none" stroke="{{ $transition && $transition['visited'] ? 'var(--color-teal-400)' : 'var(--color-zinc-200)' }}" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" aria-hidden="true">
                                            <path d="M 0 5 H 96 M 96 5 L 91 1 M 96 5 L 91 9" vector-effect="non-scaling-stroke"></path>
                                        </svg>
                                    </li>
                                    @endif
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
                    <x-date-time-picker wire:model="followedUpOn" :value="$followedUpOn" :label="__('customers.detail.followup.date')" required />
                    <flux:textarea wire:model="followupContent" :label="__('customers.detail.followup.content')" rows="4" required />
                    <flux:button type="submit" class="w-full">{{ __('customers.detail.followup.submit') }}</flux:button>
                </form>
            </section>
        </aside>
    </div>
</div>
