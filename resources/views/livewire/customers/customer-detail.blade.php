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
                    $flowTransitions = collect($statusFlow['transitions']);
                @endphp
                <div class="mt-6 overflow-x-auto pb-2" data-flow-layout>
                    <div class="flex min-w-max items-stretch gap-3">
                        @forelse ($statusFlow['stages'] as $stage)
                            <div class="w-56 min-w-56 rounded-2xl border border-zinc-200 bg-zinc-50/80 p-3 dark:border-zinc-700 dark:bg-zinc-800/60" data-stage-key="{{ $stage['key'] }}" data-stage-state="{{ $stage['state'] }}">
                                <div class="flex min-h-14 items-start justify-between gap-2 border-b border-zinc-200 pb-3 dark:border-zinc-700">
                                    <h4 class="font-semibold {{ $stage['is_active'] ? '' : 'text-zinc-500' }}">{{ $stage['name'] }}</h4>
                                    @if (! $stage['is_active'])
                                        <span class="text-xs text-zinc-500">{{ __('customers.detail.status_flow.states.inactive') }}</span>
                                    @endif
                                </div>
                                <div class="mt-3 space-y-2">
                                    @foreach ($stage['statuses'] as $status)
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
                                        @endphp
                                        <div class="rounded-xl border px-3 py-2.5 {{ $statusClasses }}" data-status-key="{{ $status['key'] }}" data-status-state="{{ $status['state'] }}" data-status-visited="{{ $status['is_visited'] ? 'true' : 'false' }}" data-status-current="{{ $status['is_current'] ? 'true' : 'false' }}">
                                            <div class="flex items-center gap-2">
                                                <span class="text-sm font-semibold" aria-hidden="true">{{ $statusMarker }}</span>
                                                <span class="font-semibold">{{ $status['name'] }}</span>
                                            </div>
                                            <span class="mt-1 block pl-5 text-xs opacity-80">{{ __('customers.detail.status_flow.states.'.$status['state']) }}</span>
                                        </div>
                                    @endforeach
                                </div>
                            </div>

                            @if (! $loop->last)
                                @php
                                    $nextStage = $statusFlow['stages'][$loop->index + 1];
                                    $stageTransitions = $flowTransitions->filter(fn (array $transition): bool => $transition['is_active'] && $transition['from_stage_id'] === $stage['id'] && $transition['to_stage_id'] === $nextStage['id']);
                                @endphp
                                <div class="flex w-40 min-w-40 flex-col justify-center gap-2" data-transition-column="{{ $stage['key'] }}-{{ $nextStage['key'] }}">
                                    @forelse ($stageTransitions as $transition)
                                        <div class="rounded-lg border border-dashed {{ $transition['is_available'] ? 'border-blue-300 bg-blue-50/70 text-blue-800 dark:border-blue-500 dark:bg-blue-950/30 dark:text-blue-100' : ($transition['visited'] ? 'border-zinc-300 bg-zinc-50 text-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300' : 'border-zinc-200 text-zinc-400 dark:border-zinc-700 dark:text-zinc-500') }} px-2 py-1.5 text-center text-xs" data-transition="{{ $transition['from_status_id'] }}-{{ $transition['to_status_id'] }}" data-transition-visited="{{ $transition['visited'] ? 'true' : 'false' }}">
                                            <span>{{ $transition['from_status_name'] }}</span>
                                            <span class="mx-1 text-base" aria-hidden="true">→</span>
                                            <span>{{ $transition['to_status_name'] }}</span>
                                        </div>
                                    @empty
                                        <span class="h-px w-full bg-zinc-200 dark:bg-zinc-700" aria-hidden="true"></span>
                                    @endforelse
                                </div>
                            @endif
                        @empty
                            <p class="py-8 text-center text-zinc-500">{{ __('customers.detail.status_flow.empty') }}</p>
                        @endforelse
                    </div>
                </div>

                @php
                    $adjacentTransitionKeys = collect($statusFlow['adjacent_stage_pairs']);
                    $secondaryTransitions = $flowTransitions->filter(function (array $transition) use ($adjacentTransitionKeys): bool {
                        return $transition['is_active'] && ! $adjacentTransitionKeys->contains(fn (array $pair): bool => $pair[0] === $transition['from_stage_id'] && $pair[1] === $transition['to_stage_id']);
                    });
                    $historicalTransitions = $flowTransitions->filter(fn (array $transition): bool => ! $transition['is_active'] && $transition['visited']);
                @endphp
                @if ($secondaryTransitions->isNotEmpty())
                    <div class="mt-5 border-t border-zinc-200 pt-4 dark:border-zinc-700" data-flow-secondary-transitions>
                        <h4 class="text-sm font-semibold">{{ __('customers.detail.status_flow.transitions') }}</h4>
                        <div class="mt-2 flex flex-wrap gap-2">
                            @foreach ($secondaryTransitions as $transition)
                                <span class="rounded-full border px-3 py-1 text-xs {{ $transition['is_available'] ? 'border-blue-300 bg-blue-50 text-blue-800 dark:border-blue-500 dark:bg-blue-950/30 dark:text-blue-100' : ($transition['visited'] ? 'border-zinc-300 text-zinc-600 dark:border-zinc-700 dark:text-zinc-300' : 'border-zinc-200 text-zinc-400 dark:border-zinc-700 dark:text-zinc-500') }}" data-transition="{{ $transition['from_status_id'] }}-{{ $transition['to_status_id'] }}" data-transition-visited="{{ $transition['visited'] ? 'true' : 'false' }}">
                                    {{ $transition['from_status_name'] }} <span aria-hidden="true">→</span> {{ $transition['to_status_name'] }}
                                </span>
                            @endforeach
                        </div>
                    </div>
                @endif
                @if ($historicalTransitions->isNotEmpty())
                    <div class="mt-5 border-t border-zinc-200 pt-4 dark:border-zinc-700" data-flow-history-transitions>
                        <h4 class="text-sm font-semibold text-zinc-500">{{ __('customers.detail.status_flow.historical_transitions') }}</h4>
                        <div class="mt-2 flex flex-wrap gap-2">
                            @foreach ($historicalTransitions as $transition)
                                <span class="rounded-full border border-zinc-300 bg-zinc-50 px-3 py-1 text-xs text-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-400" data-transition="{{ $transition['from_status_id'] }}-{{ $transition['to_status_id'] }}" data-transition-visited="true">
                                    {{ $transition['from_status_name'] }} <span aria-hidden="true">→</span> {{ $transition['to_status_name'] }}
                                </span>
                            @endforeach
                        </div>
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
