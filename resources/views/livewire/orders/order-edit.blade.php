<div>
    <x-page-back :href="route('orders.show', $orderId)" :label="__('orders.edit.back')" class="mb-4" />
    <section class="crm-section-header">
        <div>
            <p class="text-xs font-medium text-zinc-400">{{ __('orders.edit.eyebrow', ['id' => $orderId]) }}</p>
            <h2 class="mt-1 text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-50">{{ __('orders.edit_title') }}</h2>
            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ __('orders.edit.description') }}</p>
        </div>
        <flux:button href="{{ route('orders.show', $orderId) }}#status-editor" wire:navigate variant="ghost">{{ __('orders.edit.edit_status') }}</flux:button>
    </section>

    <section class="crm-card">
        <div class="rounded-xl border border-teal-200 bg-teal-50 p-4 dark:border-teal-800 dark:bg-teal-950"><p class="text-xs text-teal-700 dark:text-teal-300">{{ __('orders.edit.related_customer') }}</p><p class="mt-1 font-semibold">{{ $order['customer']['name'] }} <span class="text-sm font-normal text-zinc-500">{{ $order['customer']['code'] }}</span></p></div>
        <form wire:submit="save" class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <flux:input :value="$order['institution']['name'] ?? __('orders.values.unknown_institution')" :label="__('orders.fields.institution')" readonly />
            <flux:select wire:model.live="treatmentProjectId" :label="__('orders.fields.treatment_project')"><flux:select.option value="">{{ __('orders.fields.use_project_name') }}</flux:select.option>@foreach ($options['treatment_projects'] as $project)<flux:select.option value="{{ $project['id'] }}">{{ $project['name'] }}</flux:select.option>@endforeach</flux:select>
            <flux:input wire:model="projectName" :label="__('orders.fields.project_name')" :readonly="$treatmentProjectId !== ''" required />
            <flux:input wire:model="occurredOn" type="date" :label="__('orders.edit.occurred_on')" :required="$order['status'] === 'completed'" />
            <flux:input wire:model="quantity" type="number" min="0.001" step="0.001" :label="__('orders.edit.quantity')" required />
            <flux:input wire:model="unitPriceKrw" type="number" min="0" step="1" :label="__('orders.edit.unit_price')" required />
            <flux:input wire:model="amountKrw" type="number" min="0" step="1" :label="__('orders.fields.amount')" required />
            <flux:input wire:model="specification" :label="__('orders.edit.specification')" />
            <flux:input wire:model="translatorName" :label="__('orders.fields.translator_name')" />
            <flux:select wire:model="translatorLanguageId" :label="__('orders.fields.translator_language')"><flux:select.option value="">{{ __('orders.fields.unselected') }}</flux:select.option>@foreach ($options['translator_languages'] as $language)<flux:select.option value="{{ $language['id'] }}">{{ $language['name'] }}</flux:select.option>@endforeach</flux:select>
            <div class="md:col-span-2 xl:col-span-3"><flux:textarea wire:model="itemNotes" :label="__('orders.edit.item_notes')" rows="2" /><flux:textarea wire:model="notes" :label="__('orders.fields.notes')" rows="3" /></div>
            <div class="md:col-span-2 xl:col-span-3"><flux:textarea wire:model="reason" :label="__('orders.edit.reason')" rows="2" required /></div>
            <div class="flex justify-end gap-2 md:col-span-2 xl:col-span-3"><flux:button href="{{ route('orders.show', $orderId) }}" wire:navigate variant="ghost">{{ __('orders.edit.cancel') }}</flux:button><flux:button type="submit" variant="primary" wire:loading.attr="disabled">{{ __('orders.edit.save') }}</flux:button></div>
        </form>
    </section>
</div>
