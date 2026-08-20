<div>
    <section class="crm-section-header">
        <div>
            <h2 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-50">{{ __('config.center.title') }}</h2>
            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ __('config.center.description') }}</p>
        </div>
    </section>

    <section class="grid gap-5 lg:grid-cols-2">
        @if (app(\App\Infrastructure\Time\BusinessClock::class)->isAvailable())
            <a href="{{ route('configuration.time-travel') }}" class="group rounded-2xl border border-amber-200 bg-amber-50 p-6 shadow-sm transition hover:border-amber-300 hover:shadow-md dark:border-amber-900 dark:bg-amber-950/30 dark:hover:border-amber-700" wire:navigate data-test="configuration-time-travel-card">
                <span class="flex size-11 items-center justify-center rounded-xl bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300"><flux:icon.clock aria-hidden="true" /></span>
                <h3 class="mt-5 text-lg font-semibold">{{ __('config.center.cards.time_travel.title') }}</h3>
                <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">{{ __('config.center.cards.time_travel.description') }}</p>
                <span class="mt-5 inline-flex items-center gap-1 text-sm font-semibold text-amber-700 dark:text-amber-300">{{ __('config.center.cards.time_travel.action') }} <flux:icon.arrow-right class="size-4" aria-hidden="true" /></span>
            </a>
        @endif
        <a
            href="{{ route('configuration.history') }}"
            class="group rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm transition hover:border-teal-300 hover:shadow-md dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-teal-700"
            wire:navigate
        >
            <span class="flex size-11 items-center justify-center rounded-xl bg-teal-50 text-teal-700 dark:bg-teal-950 dark:text-teal-300"><flux:icon.clock aria-hidden="true" /></span>
            <h3 class="mt-5 text-lg font-semibold">{{ __('config.center.cards.history.title') }}</h3>
            <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">{{ __('config.center.cards.history.description') }}</p>
            <span class="mt-5 inline-flex items-center gap-1 text-sm font-semibold text-teal-700 dark:text-teal-300">{{ __('config.center.cards.history.action') }}<flux:icon.arrow-right class="size-4" aria-hidden="true" /></span>
        </a>
        <a
            href="{{ route('configuration.users-and-notifications') }}"
            class="group rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm transition hover:border-teal-300 hover:shadow-md dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-teal-700"
            wire:navigate
        >
            <span class="flex size-11 items-center justify-center rounded-xl bg-teal-50 text-teal-700 dark:bg-teal-950 dark:text-teal-300"><flux:icon.user-group aria-hidden="true" /></span>
            <h3 class="mt-5 text-lg font-semibold">{{ __('config.center.cards.users.title') }}</h3>
            <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">{{ __('config.center.cards.users.description') }}</p>
            <span class="mt-5 inline-flex items-center gap-1 text-sm font-semibold text-teal-700 dark:text-teal-300">{{ __('config.center.cards.users.action') }}<flux:icon.arrow-right class="size-4" aria-hidden="true" /></span>
        </a>
        <a
            href="{{ route('configuration.catalog') }}"
            class="group rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm transition hover:border-teal-300 hover:shadow-md dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-teal-700"
            wire:navigate
        >
            <span class="flex size-11 items-center justify-center rounded-xl bg-teal-50 text-teal-700 dark:bg-teal-950 dark:text-teal-300"><flux:icon.building-library aria-hidden="true" /></span>
            <h3 class="mt-5 text-lg font-semibold">{{ __('config.center.cards.catalog.title') }}</h3>
            <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">{{ __('config.center.cards.catalog.description') }}</p>
            <span class="mt-5 inline-flex items-center gap-1 text-sm font-semibold text-teal-700 dark:text-teal-300">{{ __('config.center.cards.catalog.action') }}<flux:icon.arrow-right class="size-4" aria-hidden="true" /></span>
        </a>
        <a
            href="{{ route('customer-statuses.index') }}"
            class="group rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm transition hover:border-teal-300 hover:shadow-md dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-teal-700"
            wire:navigate
        >
            <span class="flex size-11 items-center justify-center rounded-xl bg-teal-50 text-teal-700 dark:bg-teal-950 dark:text-teal-300">
                <flux:icon.users aria-hidden="true" />
            </span>
            <h3 class="mt-5 text-lg font-semibold">{{ __('config.center.cards.customer_statuses.title') }}</h3>
            <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">{{ __('config.center.cards.customer_statuses.description') }}</p>
            <span class="mt-5 inline-flex items-center gap-1 text-sm font-semibold text-teal-700 dark:text-teal-300">
                {{ __('config.center.cards.customer_statuses.action') }}
                <flux:icon.arrow-right class="size-4 transition group-hover:translate-x-0.5" aria-hidden="true" />
            </span>
        </a>

        <a
            href="{{ route('agent-configuration.index') }}"
            class="group rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm transition hover:border-teal-300 hover:shadow-md dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-teal-700"
            wire:navigate
        >
            <span class="flex size-11 items-center justify-center rounded-xl bg-teal-50 text-teal-700 dark:bg-teal-950 dark:text-teal-300">
                <flux:icon.building-office aria-hidden="true" />
            </span>
            <h3 class="mt-5 text-lg font-semibold">{{ __('config.center.cards.agent.title') }}</h3>
            <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">{{ __('config.center.cards.agent.description') }}</p>
            <span class="mt-5 inline-flex items-center gap-1 text-sm font-semibold text-teal-700 dark:text-teal-300">
                {{ __('config.center.cards.agent.action') }}
                <flux:icon.arrow-right class="size-4 transition group-hover:translate-x-0.5" aria-hidden="true" />
            </span>
        </a>

        <a
            href="{{ route('reminder-configuration.index') }}"
            class="group rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm transition hover:border-teal-300 hover:shadow-md dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-teal-700"
            wire:navigate
        >
            <span class="flex size-11 items-center justify-center rounded-xl bg-teal-50 text-teal-700 dark:bg-teal-950 dark:text-teal-300"><flux:icon.bell-alert aria-hidden="true" /></span>
            <h3 class="mt-5 text-lg font-semibold">{{ __('config.center.cards.reminders.title') }}</h3>
            <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">{{ __('config.center.cards.reminders.description') }}</p>
            <span class="mt-5 inline-flex items-center gap-1 text-sm font-semibold text-teal-700 dark:text-teal-300">{{ __('config.center.cards.reminders.action') }}<flux:icon.arrow-right class="size-4" aria-hidden="true" /></span>
        </a>

        <a
            href="{{ route('configuration.data-maintenance') }}"
            class="group rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm transition hover:border-teal-300 hover:shadow-md dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-teal-700"
            wire:navigate
        >
            <span class="flex size-11 items-center justify-center rounded-xl bg-teal-50 text-teal-700 dark:bg-teal-950 dark:text-teal-300">
                <flux:icon.arrow-up-tray aria-hidden="true" />
            </span>
            <h3 class="mt-5 text-lg font-semibold">{{ __('config.center.cards.data_maintenance.title') }}</h3>
            <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">{{ __('config.center.cards.data_maintenance.description') }}</p>
            <span class="mt-5 inline-flex items-center gap-1 text-sm font-semibold text-teal-700 dark:text-teal-300">{{ __('config.center.cards.data_maintenance.action') }}<flux:icon.arrow-right class="size-4" aria-hidden="true" /></span>
        </a>
    </section>
</div>
