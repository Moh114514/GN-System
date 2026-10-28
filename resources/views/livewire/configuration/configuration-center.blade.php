<div>
    <section class="crm-section-header">
        <div>
            <h2 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-50">{{ __('config.center.title') }}</h2>
            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ __('config.center.description') }}</p>
        </div>
    </section>

    <div class="space-y-8">
        <section data-test="configuration-section-employees">
            <h3 class="mb-3 text-lg font-semibold">{{ __('config.center.groups.employees') }}</h3>
            <div class="grid gap-5 lg:grid-cols-2">
                <a href="{{ route('configuration.users-and-notifications') }}" class="group rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm transition hover:border-teal-300 hover:shadow-md dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-teal-700" wire:navigate data-test="configuration-card-users">
                    <span class="flex size-11 items-center justify-center rounded-xl bg-teal-50 text-teal-700 dark:bg-teal-950 dark:text-teal-300"><flux:icon.user-group aria-hidden="true" /></span>
                    <h4 class="mt-5 text-lg font-semibold">{{ __('config.center.cards.users.title') }}</h4>
                    <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">{{ __('config.center.cards.users.description') }}</p>
                    <span class="mt-5 inline-flex items-center gap-1 text-sm font-semibold text-teal-700 dark:text-teal-300">{{ __('config.center.cards.users.action') }} <flux:icon.arrow-right class="size-4" aria-hidden="true" /></span>
                </a>
            </div>
        </section>

        <section data-test="configuration-section-institutions">
            <h3 class="mb-3 text-lg font-semibold">{{ __('config.center.groups.institutions') }}</h3>
            <div class="grid gap-5 lg:grid-cols-2">
                <a href="{{ route('configuration.catalog') }}" class="group rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm transition hover:border-teal-300 hover:shadow-md dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-teal-700" wire:navigate data-test="configuration-card-catalog">
                    <span class="flex size-11 items-center justify-center rounded-xl bg-teal-50 text-teal-700 dark:bg-teal-950 dark:text-teal-300"><flux:icon.building-library aria-hidden="true" /></span>
                    <h4 class="mt-5 text-lg font-semibold">{{ __('config.center.cards.catalog.title') }}</h4>
                    <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">{{ __('config.center.cards.catalog.description') }}</p>
                    <span class="mt-5 inline-flex items-center gap-1 text-sm font-semibold text-teal-700 dark:text-teal-300">{{ __('config.center.cards.catalog.action') }} <flux:icon.arrow-right class="size-4" aria-hidden="true" /></span>
                </a>
            </div>
        </section>

        <section data-test="configuration-section-commissions">
            <h3 class="mb-3 text-lg font-semibold">{{ __('config.center.groups.commissions') }}</h3>
            <div class="grid gap-5 lg:grid-cols-2">
                <a href="{{ route('configuration.direct-customer') }}" class="group rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm transition hover:border-teal-300 hover:shadow-md dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-teal-700" wire:navigate>
                    <span class="flex size-11 items-center justify-center rounded-xl bg-teal-50 text-teal-700 dark:bg-teal-950 dark:text-teal-300"><flux:icon.banknotes aria-hidden="true" /></span>
                    <h4 class="mt-5 text-lg font-semibold">{{ __('config.center.cards.direct_customer.title') }}</h4>
                    <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">{{ __('config.center.cards.direct_customer.description') }}</p>
                    <span class="mt-5 inline-flex items-center gap-1 text-sm font-semibold text-teal-700 dark:text-teal-300">{{ __('config.center.cards.direct_customer.action') }} <flux:icon.arrow-right class="size-4" aria-hidden="true" /></span>
                </a>
                <a href="{{ route('bd-commissions.index') }}" class="group rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm transition hover:border-teal-300 hover:shadow-md dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-teal-700" wire:navigate data-test="configuration-card-bd-commissions">
                    <span class="flex size-11 items-center justify-center rounded-xl bg-teal-50 text-teal-700 dark:bg-teal-950 dark:text-teal-300"><flux:icon.banknotes aria-hidden="true" /></span>
                    <h4 class="mt-5 text-lg font-semibold">{{ __('config.center.cards.bd_commission.title') }}</h4>
                    <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">{{ __('config.center.cards.bd_commission.description') }}</p>
                    <span class="mt-5 inline-flex items-center gap-1 text-sm font-semibold text-teal-700 dark:text-teal-300">{{ __('config.center.cards.bd_commission.action') }} <flux:icon.arrow-right class="size-4" aria-hidden="true" /></span>
                </a>
                <a href="{{ route('agent-configuration.index') }}" class="group rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm transition hover:border-teal-300 hover:shadow-md dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-teal-700" wire:navigate>
                    <span class="flex size-11 items-center justify-center rounded-xl bg-teal-50 text-teal-700 dark:bg-teal-950 dark:text-teal-300"><flux:icon.building-office aria-hidden="true" /></span>
                    <h4 class="mt-5 text-lg font-semibold">{{ __('config.center.cards.agent.title') }}</h4>
                    <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">{{ __('config.center.cards.agent.description') }}</p>
                    <span class="mt-5 inline-flex items-center gap-1 text-sm font-semibold text-teal-700 dark:text-teal-300">{{ __('config.center.cards.agent.action') }} <flux:icon.arrow-right class="size-4" aria-hidden="true" /></span>
                </a>
            </div>
        </section>

        <section data-test="configuration-section-basics">
            <h3 class="mb-3 text-lg font-semibold">{{ __('config.center.groups.basics') }}</h3>
            <div class="grid gap-5 lg:grid-cols-2">
                <a href="{{ route('customer-statuses.index') }}" class="group rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm transition hover:border-teal-300 hover:shadow-md dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-teal-700" wire:navigate>
                    <span class="flex size-11 items-center justify-center rounded-xl bg-teal-50 text-teal-700 dark:bg-teal-950 dark:text-teal-300"><flux:icon.users aria-hidden="true" /></span>
                    <h4 class="mt-5 text-lg font-semibold">{{ __('config.center.cards.customer_statuses.title') }}</h4>
                    <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">{{ __('config.center.cards.customer_statuses.description') }}</p>
                    <span class="mt-5 inline-flex items-center gap-1 text-sm font-semibold text-teal-700 dark:text-teal-300">{{ __('config.center.cards.customer_statuses.action') }} <flux:icon.arrow-right class="size-4" aria-hidden="true" /></span>
                </a>
                <a href="{{ route('reminder-configuration.index') }}" class="group rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm transition hover:border-teal-300 hover:shadow-md dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-teal-700" wire:navigate>
                    <span class="flex size-11 items-center justify-center rounded-xl bg-teal-50 text-teal-700 dark:bg-teal-950 dark:text-teal-300"><flux:icon.bell-alert aria-hidden="true" /></span>
                    <h4 class="mt-5 text-lg font-semibold">{{ __('config.center.cards.reminders.title') }}</h4>
                    <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">{{ __('config.center.cards.reminders.description') }}</p>
                    <span class="mt-5 inline-flex items-center gap-1 text-sm font-semibold text-teal-700 dark:text-teal-300">{{ __('config.center.cards.reminders.action') }} <flux:icon.arrow-right class="size-4" aria-hidden="true" /></span>
                </a>
                @if (auth()->user()->isSuperAdmin())
                    <a href="{{ route('configuration.data-maintenance') }}" class="group rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm transition hover:border-teal-300 hover:shadow-md dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-teal-700" wire:navigate>
                        <span class="flex size-11 items-center justify-center rounded-xl bg-teal-50 text-teal-700 dark:bg-teal-950 dark:text-teal-300"><flux:icon.arrow-path aria-hidden="true" /></span>
                        <h4 class="mt-5 text-lg font-semibold">{{ __('config.center.cards.data_maintenance.title') }}</h4>
                        <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">{{ __('config.center.cards.data_maintenance.description') }}</p>
                        <span class="mt-5 inline-flex items-center gap-1 text-sm font-semibold text-teal-700 dark:text-teal-300">{{ __('config.center.cards.data_maintenance.action') }} <flux:icon.arrow-right class="size-4" aria-hidden="true" /></span>
                    </a>
                @endif
                <a href="{{ route('configuration.history') }}" class="group rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm transition hover:border-teal-300 hover:shadow-md dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-teal-700" wire:navigate>
                    <span class="flex size-11 items-center justify-center rounded-xl bg-teal-50 text-teal-700 dark:bg-teal-950 dark:text-teal-300"><flux:icon.clock aria-hidden="true" /></span>
                    <h4 class="mt-5 text-lg font-semibold">{{ __('config.center.cards.history.title') }}</h4>
                    <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">{{ __('config.center.cards.history.description') }}</p>
                    <span class="mt-5 inline-flex items-center gap-1 text-sm font-semibold text-teal-700 dark:text-teal-300">{{ __('config.center.cards.history.action') }} <flux:icon.arrow-right class="size-4" aria-hidden="true" /></span>
                </a>
                @if (auth()->user()->isSuperAdmin() && app(\App\Infrastructure\Time\BusinessClock::class)->isAvailable())
                    <a href="{{ route('configuration.time-travel') }}" class="group rounded-2xl border border-amber-200 bg-amber-50 p-6 shadow-sm transition hover:border-amber-300 hover:shadow-md dark:border-amber-900 dark:bg-amber-950/30 dark:hover:border-amber-700" wire:navigate data-test="configuration-time-travel-card">
                        <span class="flex size-11 items-center justify-center rounded-xl bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300"><flux:icon.clock aria-hidden="true" /></span>
                        <h4 class="mt-5 text-lg font-semibold">{{ __('config.center.cards.time_travel.title') }}</h4>
                        <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">{{ __('config.center.cards.time_travel.description') }}</p>
                        <span class="mt-5 inline-flex items-center gap-1 text-sm font-semibold text-amber-700 dark:text-amber-300">{{ __('config.center.cards.time_travel.action') }} <flux:icon.arrow-right class="size-4" aria-hidden="true" /></span>
                    </a>
                @endif
            </div>
        </section>
    </div>
</div>
