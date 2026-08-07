<div>
    <x-page-back :href="route('configuration.index')" :label="__('config.customer_status.back')" class="mb-4" />

    <section class="crm-section-header">
        <div>
            <p class="text-xs font-medium text-zinc-400">{{ __('config.customer_status.eyebrow') }}</p>
            <h2 class="mt-1 text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-50">{{ __('config.customer_status.title') }}</h2>
            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ __('config.customer_status.description') }}</p>
        </div>
    </section>

    <form wire:submit="save" class="space-y-6">
        <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <h3 class="text-lg font-semibold">{{ __('config.customer_status.stages_heading') }}</h3>
            <div class="mt-5 space-y-3">
                @forelse ($stages as $index => $stage)
                    <div class="grid items-end gap-3 rounded-xl border border-zinc-200 p-4 md:grid-cols-[1fr_8rem_8rem]" wire:key="stage-{{ $stage['id'] }}">
                        <flux:input
                            wire:model="stages.{{ $index }}.name"
                            label="{{ $stage['key'] }}"
                            :title="__('config.customer_status.stage_name_title', ['key' => $stage['key']])"
                        />
                        <flux:input
                            wire:model="stages.{{ $index }}.sort_order"
                            type="number"
                            :label="__('config.customer_status.sort_order')"
                            :title="__('config.customer_status.stage_sort_title')"
                        />
                        <flux:checkbox
                            wire:model="stages.{{ $index }}.is_active"
                            :label="__('config.customer_status.enabled')"
                            :title="__('config.customer_status.stage_enabled_title')"
                        />
                    </div>
                @empty
                    <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                        {{ __('config.customer_status.empty_stages') }}
                    </div>
                @endforelse
            </div>
        </section>

        <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <h3 class="text-lg font-semibold">{{ __('config.customer_status.statuses_heading') }}</h3>
            <div class="mt-5 space-y-4">
                @forelse ($statuses as $index => $status)
                    <div class="rounded-xl border border-zinc-200 p-4" wire:key="status-{{ $status['id'] }}">
                        <div class="grid items-end gap-3 md:grid-cols-4">
                            <flux:input
                                wire:model="statuses.{{ $index }}.name"
                                label="{{ $status['key'] }}"
                                :title="__('config.customer_status.status_name_title', ['key' => $status['key']])"
                            />
                            <flux:select
                                wire:model="statuses.{{ $index }}.stage_id"
                                :label="__('config.customer_status.stage')"
                                :title="__('config.customer_status.status_stage_title')"
                            >
                                @foreach ($stages as $stage)
                                    <flux:select.option value="{{ $stage['id'] }}">{{ $stage['name'] }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            <flux:input
                                wire:model="statuses.{{ $index }}.sort_order"
                                type="number"
                                :label="__('config.customer_status.sort_order')"
                                :title="__('config.customer_status.status_sort_title')"
                            />
                            <flux:checkbox
                                wire:model="statuses.{{ $index }}.is_active"
                                :label="__('config.customer_status.enabled')"
                                :title="__('config.customer_status.status_enabled_title')"
                            />
                        </div>
                        <div class="mt-4">
                            <p
                                class="mb-2 text-xs font-medium text-zinc-500"
                                :title="__('config.customer_status.allow_forward_title')"
                            >{{ __('config.customer_status.allow_forward') }}</p>
                            <div class="flex flex-wrap gap-4">
                                @foreach ($statuses as $target)
                                    @if ($target['id'] !== $status['id'])
                                        <flux:checkbox
                                            wire:model="statuses.{{ $index }}.to_status_ids"
                                            value="{{ $target['id'] }}"
                                            label="{{ $target['name'] }}"
                                            :title="__('config.customer_status.transition_title', ['from' => $status['name'], 'to' => $target['name']])"
                                        />
                                    @endif
                                @endforeach
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                        {{ __('config.customer_status.empty_statuses') }}
                    </div>
                @endforelse
            </div>
        </section>

        @if ($errors->any())
            <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                <ul class="list-disc space-y-1 pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </div>
        @endif
        <div class="flex justify-end gap-3">
            <flux:button type="submit" variant="primary">{{ __('config.customer_status.save') }}</flux:button>
        </div>
    </form>
</div>
