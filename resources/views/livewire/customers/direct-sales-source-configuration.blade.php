<div>
    <x-page-back :href="route('configuration.index')" :label="__('config.direct_sales_source.back')" class="mb-4" />
    <section class="crm-section-header">
        <div><p class="text-xs font-medium text-zinc-400">{{ __('config.direct_sales_source.eyebrow') }}</p><h2 class="mt-1 text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-50">{{ __('config.direct_sales_source.title') }}</h2><p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ __('config.direct_sales_source.description') }}</p></div>
    </section>
    <section class="grid gap-6 xl:grid-cols-[22rem_1fr]">
        <form wire:submit="save" class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <h3 class="font-semibold">{{ $editingId === null ? __('config.direct_sales_source.create_heading') : __('config.direct_sales_source.edit_heading') }}</h3>
            <div class="mt-4 space-y-3">
                <flux:input wire:model="code" :label="__('config.direct_sales_source.code')" />
                <flux:input wire:model="name" :label="__('config.direct_sales_source.name')" />
                <flux:button type="submit" variant="primary">{{ __('config.direct_sales_source.save') }}</flux:button>
            </div>
        </form>
        <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="crm-table-wrap">
                <table class="crm-table">
                    <thead><tr><th>{{ __('config.direct_sales_source.table.code') }}</th><th>{{ __('config.direct_sales_source.table.name') }}</th><th>{{ __('config.direct_sales_source.table.status') }}</th><th>{{ __('config.direct_sales_source.table.actions') }}</th></tr></thead>
                    <tbody>
                        @foreach ($sources as $source)
                            <tr>
                                <td>{{ $source['code'] }}</td><td>{{ $source['name'] }}</td><td>{{ $source['is_active'] ? __('config.direct_sales_source.actions.enable') : __('config.direct_sales_source.actions.disable') }}</td>
                                <td class="space-x-2">
                                    <flux:button wire:click="edit({{ $source['id'] }})" variant="ghost" size="sm">{{ __('config.direct_sales_source.actions.edit') }}</flux:button>
                                    <flux:button wire:click="toggle({{ $source['id'] }})" variant="ghost" size="sm">{{ $source['is_active'] ? __('config.direct_sales_source.actions.disable') : __('config.direct_sales_source.actions.enable') }}</flux:button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</div>
