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
            <flux:input wire:model="occurredOn" type="date" :label="__('orders.edit.occurred_on')" :required="$order['status'] === 'completed'" />
            <flux:input wire:model="translatorName" :label="__('orders.fields.translator_name')" />
            <flux:select wire:model="translatorLanguageId" :label="__('orders.fields.translator_language')"><flux:select.option value="">{{ __('orders.fields.unselected') }}</flux:select.option>@foreach ($options['translator_languages'] as $language)<flux:select.option value="{{ $language['id'] }}">{{ $language['name'] }}</flux:select.option>@endforeach</flux:select>
            <div class="md:col-span-2"><flux:textarea wire:model="notes" :label="__('orders.fields.notes')" rows="2" /></div>

            <div class="md:col-span-2 xl:col-span-3">
                <div class="flex items-center justify-between gap-3"><h3 class="font-semibold">{{ __('orders.edit.items') }}</h3><flux:button type="button" wire:click="addItem" variant="ghost">{{ __('orders.edit.add_item') }}</flux:button></div>
                <div class="mt-3 space-y-4">
                    @foreach ($items as $index => $item)
                        <section wire:key="order-edit-item-{{ $index }}" class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                            <div class="mb-3 flex items-center justify-between"><h4 class="font-medium">{{ __('orders.edit.item_number', ['number' => $index + 1]) }}</h4><flux:button type="button" wire:click="removeItem({{ $index }})" variant="ghost" size="sm">{{ __('orders.edit.remove_item') }}</flux:button></div>
                            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                                <flux:select wire:model="items.{{ $index }}.treatment_project_id" wire:change="selectTreatmentProject({{ $index }})" :label="__('orders.fields.treatment_project')"><flux:select.option value="">{{ __('orders.fields.use_project_name') }}</flux:select.option>@foreach ($options['treatment_projects'] as $project)<flux:select.option value="{{ $project['id'] }}">{{ $project['name'] }}</flux:select.option>@endforeach</flux:select>
                                <flux:input wire:model="items.{{ $index }}.project_name" :label="__('orders.fields.project_name')" required />
                                <flux:input wire:model="items.{{ $index }}.quantity" type="number" min="0.001" step="0.001" :label="__('orders.edit.quantity')" required />
                                <flux:input wire:model="items.{{ $index }}.unit_price_krw" type="number" min="0" step="1" :label="__('orders.edit.unit_price')" required />
                                <flux:input wire:model="items.{{ $index }}.amount_krw" type="number" min="0" step="1" :label="__('orders.fields.amount')" required />
                                <flux:input wire:model="items.{{ $index }}.specification" :label="__('orders.edit.specification')" />
                                <div class="md:col-span-2 xl:col-span-3"><flux:textarea wire:model="items.{{ $index }}.notes" :label="__('orders.edit.item_notes')" rows="2" /></div>
                            </div>
                        </section>
                    @endforeach
                </div>
                <p class="mt-3 text-right font-semibold">{{ __('orders.edit.total_amount') }}：₩ {{ number_format(collect($items)->sum(fn ($item) => (int) $item['amount_krw'])) }}</p>
            </div>

            <div class="md:col-span-2 xl:col-span-3"><flux:textarea wire:model="reason" :label="__('orders.edit.reason')" rows="2" required /></div>
            <div class="flex justify-end gap-2 md:col-span-2 xl:col-span-3"><flux:button href="{{ route('orders.show', $orderId) }}" wire:navigate variant="ghost">{{ __('orders.edit.cancel') }}</flux:button><flux:button type="submit" variant="primary" wire:loading.attr="disabled">{{ __('orders.edit.save') }}</flux:button></div>
        </form>
    </section>
</div>
