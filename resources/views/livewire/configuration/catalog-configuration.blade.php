<div>
    <x-page-back :href="route('configuration.index')" :label="__('config.back_to_configuration')" class="mb-4" />
    <section class="crm-section-header">
        <div>
            <p class="text-xs font-medium text-zinc-400">{{ __('config.catalog.eyebrow') }}</p>
            <h2 class="mt-1 text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-50">{{ __('config.catalog.title') }}</h2>
            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ __('config.catalog.description') }}</p>
        </div>
    </section>

    <section class="grid gap-6 xl:grid-cols-[24rem_1fr]">
        <form wire:submit="saveInstitution" class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <h3 class="font-semibold">{{ $institutionId === null ? __('config.catalog.create_institution') : __('config.catalog.edit_institution') }}</h3>
            <div class="mt-4 space-y-3">
                <flux:input wire:model="institutionCode" :label="__('config.catalog.institution_code')" />
                <flux:input wire:model="institutionName" :label="__('config.catalog.institution_name')" />
                <flux:input wire:model="institutionAddress" :label="__('config.catalog.address')" />
                <flux:input wire:model="institutionContactName" :label="__('config.catalog.contact_name')" />
                <flux:input wire:model="institutionContactValue" :label="__('config.catalog.contact_value')" />
                <div class="flex gap-2">
                    <flux:button type="submit" variant="primary">{{ __('config.catalog.save_institution') }}</flux:button>
                    @if ($institutionId !== null)<flux:button type="button" wire:click="cancelInstitution" variant="ghost">{{ __('config.catalog.cancel') }}</flux:button>@endif
                </div>
            </div>
        </form>
        <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <h3 class="font-semibold">{{ __('config.catalog.institution_list') }}</h3>
            <div class="crm-table-wrap mt-4">
                <table class="crm-table">
                    <thead><tr><th>{{ __('config.catalog.table.code_name') }}</th><th>{{ __('config.catalog.table.contact') }}</th><th>{{ __('config.catalog.table.status') }}</th><th>{{ __('config.catalog.table.actions') }}</th></tr></thead>
                    <tbody>
                        @foreach ($state['institutions'] as $institution)
                            <tr>
                                <td><strong>{{ $institution['code'] }}</strong><br>{{ $institution['name'] }}<br><span class="text-xs text-zinc-500">{{ $institution['address'] ?: __('config.catalog.address_empty') }}</span></td>
                                <td>{{ $institution['contact_name'] ?: '—' }}<br>{{ $institution['contact_value'] ?: '—' }}</td>
                                <td>{{ $institution['is_active'] ? __('config.status.enabled') : __('config.status.disabled') }}</td>
                                <td>
                                    <div class="flex flex-wrap gap-2">
                                        <flux:button wire:click="editInstitution({{ $institution['id'] }})" variant="ghost" size="sm">{{ __('config.catalog.actions.edit') }}</flux:button>
                                        <flux:button wire:click="toggleInstitution({{ $institution['id'] }})" variant="ghost" size="sm">{{ $institution['is_active'] ? __('config.catalog.actions.disable') : __('config.catalog.actions.enable') }}</flux:button>
                                        <flux:button wire:click="deleteInstitution({{ $institution['id'] }})" :wire:confirm="__('config.catalog.delete_institution_confirm')" variant="ghost" size="sm">{{ __('config.catalog.actions.delete') }}</flux:button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section class="mt-6 grid gap-6 xl:grid-cols-[24rem_1fr]">
        <form wire:submit="saveDictionary" class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <h3 class="font-semibold">{{ __('config.catalog.dictionary_form_heading') }}</h3>
            <div class="mt-4 space-y-3">
                <flux:select wire:model="dictionaryType" :label="__('config.catalog.dictionary_type')">
                    <option value="treatment_project">{{ __('config.catalog.dictionary_types.treatment_project') }}</option>
                    <option value="translator_language">{{ __('config.catalog.dictionary_types.translator_language') }}</option>
                </flux:select>
                <flux:input wire:model="dictionaryCode" :label="__('config.catalog.stable_code')" />
                <flux:input wire:model="dictionaryName" :label="__('config.catalog.display_name')" />
                <flux:button type="submit" variant="primary">{{ __('config.catalog.save_dictionary') }}</flux:button>
            </div>
        </form>
        <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <h3 class="font-semibold">{{ __('config.catalog.dictionary_list') }}</h3>
            <div class="crm-table-wrap mt-4">
                <table class="crm-table">
                    <thead><tr><th>{{ __('config.catalog.table.type') }}</th><th>{{ __('config.catalog.table.code') }}</th><th>{{ __('config.catalog.table.name') }}</th><th>{{ __('config.catalog.table.status') }}</th><th>{{ __('config.catalog.table.actions') }}</th></tr></thead>
                    <tbody>
                        @foreach ($state['dictionary_items'] as $item)
                            <tr>
                                <td>{{ $item['type'] === 'treatment_project' ? __('config.catalog.dictionary_types.treatment_project') : __('config.catalog.dictionary_types.translator_language') }}</td>
                                <td>{{ $item['code'] }}</td><td>{{ $item['name'] }}</td><td>{{ $item['is_active'] ? __('config.status.enabled') : __('config.status.disabled') }}</td>
                                <td class="space-x-2">
                                    <flux:button wire:click="editDictionary({{ $item['id'] }})" variant="ghost" size="sm">{{ __('config.catalog.actions.edit') }}</flux:button>
                                    <flux:button wire:click="toggleDictionary({{ $item['id'] }})" variant="ghost" size="sm">{{ $item['is_active'] ? __('config.catalog.actions.disable') : __('config.catalog.actions.enable') }}</flux:button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <form wire:submit="saveParameters" class="mt-6 rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <h3 class="font-semibold">{{ __('config.catalog.parameters_heading') }}</h3>
        <p class="mt-1 text-sm text-zinc-500">{{ __('config.catalog.parameters_description') }}</p>
        <div class="mt-4 grid gap-4 sm:grid-cols-2">
            <flux:input wire:model="reportDefaultPerPage" type="number" min="10" max="200" :label="__('config.catalog.report_default_per_page')" />
            <flux:input wire:model="dashboardRefreshSeconds" type="number" min="60" max="3600" :label="__('config.catalog.dashboard_refresh_seconds')" />
        </div>
        <flux:button type="submit" variant="primary" class="mt-4">{{ __('config.catalog.save_parameters') }}</flux:button>
    </form>
</div>
