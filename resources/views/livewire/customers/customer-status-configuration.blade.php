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
                    <div class="rounded-xl border border-zinc-200 p-4" wire:key="stage-{{ $stage['id'] }}">
                        <flux:input
                            wire:model="stages.{{ $index }}.name"
                            label="{{ $stage['key'] }}"
                            :title="__('config.customer_status.stage_name_title', ['key' => $stage['key']])"
                        />
                        <p class="mt-2 text-xs text-zinc-500">{{ __('config.customer_status.structure_locked') }}</p>
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
                        <div>
                            <flux:input
                                wire:model="statuses.{{ $index }}.name"
                                label="{{ $status['key'] }}"
                                :title="__('config.customer_status.status_name_title', ['key' => $status['key']])"
                            />
                            <p class="mt-2 text-xs text-zinc-500">{{ __('config.customer_status.structure_locked') }}</p>
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
