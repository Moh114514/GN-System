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
