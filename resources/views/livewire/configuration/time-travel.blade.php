<div>
    <x-page-back :href="route('configuration.index')" :label="__('config.back_to_configuration')" class="mb-4" />

    <section class="crm-section-header">
        <div>
            <p class="text-xs font-medium text-amber-600 dark:text-amber-400">{{ __('config.time_travel.eyebrow') }}</p>
            <h2 class="mt-1 text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-50">{{ __('config.time_travel.title') }}</h2>
            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ __('config.time_travel.description') }}</p>
        </div>
    </section>

    <section class="grid gap-6 xl:grid-cols-[24rem_1fr]">
        <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="space-y-3 text-sm">
                <div class="flex items-center justify-between gap-4"><span>{{ __('config.time_travel.real_time') }}</span><strong>{{ $realNow->format('Y-m-d H:i') }}</strong></div>
                <div class="flex items-center justify-between gap-4"><span>{{ __('config.time_travel.business_time') }}</span><strong>{{ $businessNow->format('Y-m-d H:i') }}</strong></div>
                <div class="flex items-center justify-between gap-4"><span>{{ __('config.time_travel.status') }}</span><strong class="{{ $active ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400' }}">{{ $active ? __('config.time_travel.active') : __('config.time_travel.inactive') }}</strong></div>
            </div>

            <form wire:submit="enable" class="mt-6 space-y-4">
                <h3 class="font-semibold">{{ __('config.time_travel.set_heading') }}</h3>
                <div class="grid gap-3 sm:grid-cols-2">
                    <x-date-time-picker wire:model="simulationDate" :value="$simulationDate" :label="__('config.time_travel.date')" />
                    <x-date-time-picker wire:model="simulationTime" :value="$simulationTime" mode="time" :label="__('config.time_travel.time')" />
                </div>
                <flux:button type="submit" variant="primary" color="amber">{{ __('config.time_travel.enable') }}</flux:button>
            </form>

            <div class="mt-6 border-t border-zinc-200 pt-5 dark:border-zinc-700">
                <h3 class="font-semibold">{{ __('config.time_travel.shortcuts') }}</h3>
                <div class="mt-3 flex flex-wrap gap-2">
                    <flux:button wire:click="adjust('day')" variant="ghost" size="sm">{{ __('config.time_travel.plus_day') }}</flux:button>
                    <flux:button wire:click="adjust('week')" variant="ghost" size="sm">{{ __('config.time_travel.plus_week') }}</flux:button>
                    <flux:button wire:click="adjust('30_days')" variant="ghost" size="sm">{{ __('config.time_travel.plus_30_days') }}</flux:button>
                    <flux:button wire:click="adjust('month')" variant="ghost" size="sm">{{ __('config.time_travel.plus_month') }}</flux:button>
                </div>
            </div>

            <flux:button wire:click="restore" variant="ghost" class="mt-5">{{ __('config.time_travel.restore') }}</flux:button>
        </div>

        <div class="rounded-2xl border border-amber-200 bg-amber-50/50 p-5 shadow-sm dark:border-amber-900 dark:bg-amber-950/20">
            <h3 class="font-semibold text-amber-950 dark:text-amber-100">{{ __('config.time_travel.execute_heading') }}</h3>
            <p class="mt-2 text-sm text-amber-900/80 dark:text-amber-100/80">{{ __('config.time_travel.execute_description') }}</p>
            <div class="mt-5 space-y-3">
                <flux:checkbox wire:model="runSettlements" :label="__('config.time_travel.run_settlements')" />
                <flux:checkbox wire:model="runReminderMaterialization" :label="__('config.time_travel.run_reminders')" />
                <flux:checkbox wire:model="runReminderDispatch" :label="__('config.time_travel.run_notifications')" />
            </div>
            <flux:button wire:click="setAndExecute" variant="primary" color="amber" class="mt-5">{{ __('config.time_travel.set_and_execute') }}</flux:button>
            @if ($lastExecution !== '')
                <p class="mt-4 text-sm font-medium text-emerald-700 dark:text-emerald-300">{{ __('config.time_travel.last_execution', ['actions' => $lastExecution]) }}</p>
            @endif
        </div>
    </section>
</div>
