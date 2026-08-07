<div>
    <x-page-back
        :href="route('configuration.index')"
        :label="__('config.back_to_configuration')"
        class="mb-4"
    />

    <section class="crm-section-header">
        <div>
            <h2 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-50">{{ __('config.data_maintenance.title') }}</h2>
            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ __('config.data_maintenance.description') }}</p>
        </div>
    </section>

    <section class="grid gap-5 lg:grid-cols-2">
        <a
            href="{{ route('reference-configuration-imports.index') }}"
            class="group rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm transition hover:border-teal-300 hover:shadow-md dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-teal-700"
            wire:navigate
        >
            <span class="flex size-11 items-center justify-center rounded-xl bg-teal-50 text-teal-700 dark:bg-teal-950 dark:text-teal-300"><flux:icon.arrow-up-tray aria-hidden="true" /></span>
            <h3 class="mt-5 text-lg font-semibold">{{ __('config.data_maintenance.reference_import.title') }}</h3>
            <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">{{ __('config.data_maintenance.reference_import.description') }}</p>
            <span class="mt-5 inline-flex items-center gap-1 text-sm font-semibold text-teal-700 dark:text-teal-300">{{ __('config.data_maintenance.reference_import.action') }}<flux:icon.arrow-right class="size-4" aria-hidden="true" /></span>
        </a>

        <a
            href="{{ route('data-imports.index') }}"
            class="group rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm transition hover:border-teal-300 hover:shadow-md dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-teal-700"
            wire:navigate
        >
            <span class="flex size-11 items-center justify-center rounded-xl bg-teal-50 text-teal-700 dark:bg-teal-950 dark:text-teal-300"><flux:icon.arrow-up-tray aria-hidden="true" /></span>
            <h3 class="mt-5 text-lg font-semibold">{{ __('config.data_maintenance.historical_import.title') }}</h3>
            <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">{{ __('config.data_maintenance.historical_import.description') }}</p>
            <span class="mt-5 inline-flex items-center gap-1 text-sm font-semibold text-teal-700 dark:text-teal-300">{{ __('config.data_maintenance.historical_import.action') }}<flux:icon.arrow-right class="size-4" aria-hidden="true" /></span>
        </a>
    </section>
</div>
