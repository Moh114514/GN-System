<div>
    <x-page-back :href="route('customers.show', $customerId)" :label="__('orders.customer.back')" class="mb-4" />
    <section class="mb-6">
        <p class="text-xs font-medium text-zinc-400">{{ __('orders.customer.eyebrow', ['code' => $context['customer']['code']]) }}</p>
        <h2 class="mt-1 text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-50"><span class="font-semibold">{{ $context['customer']['name'] }}</span>{{ __('orders.customer.heading_suffix') }}</h2>
        <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ __('orders.customer.description') }}</p>
    </section>


    <div class="grid gap-6 xl:grid-cols-[24rem_minmax(0,1fr)]">
        <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <h3 class="font-semibold">{{ __('orders.customer.register') }}</h3>
            <form wire:submit="save" class="mt-4 space-y-4">
                <flux:select wire:model="institutionId" :label="__('orders.fields.institution')" required>
                    <flux:select.option value="">{{ __('orders.fields.select') }}</flux:select.option>
                    @foreach ($context['institutions'] as $institution)
                        <flux:select.option value="{{ $institution['id'] }}">{{ $institution['name'] }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model="agentId" :label="__('orders.fields.agent')" required>
                    <flux:select.option value="">{{ __('orders.fields.select') }}</flux:select.option>
                    @foreach ($context['agents'] as $agent)
                        <flux:select.option value="{{ $agent['id'] }}">{{ $agent['code'] }} · {{ $agent['name'] }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model="treatmentProjectId" :label="__('orders.fields.treatment_project')">
                    <flux:select.option value="">{{ __('orders.fields.manual_project') }}</flux:select.option>
                    @foreach ($context['treatment_projects'] as $project)
                        <flux:select.option value="{{ $project['id'] }}">{{ $project['name'] }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="projectName" :label="__('orders.fields.project_snapshot')" :required="$treatmentProjectId === ''" />
                <flux:input wire:model="amountKrw" type="number" min="0" step="1" :label="__('orders.fields.amount')" required />
                <flux:select wire:model.live="status" :label="__('orders.fields.status')" required>
                    <flux:select.option value="pending">{{ __('orders.statuses.pending') }}</flux:select.option>
                    <flux:select.option value="completed">{{ __('orders.statuses.completed') }}</flux:select.option>
                </flux:select>
                @if ($status === 'completed')
                            <x-date-time-picker wire:model="completedOn" mode="datetime" :label="__('orders.fields.completed_at')" required />
                @endif
                <flux:select wire:model="translatorLanguageId" :label="__('orders.fields.translator_language')">
                    <flux:select.option value="">{{ __('orders.fields.unselected') }}</flux:select.option>
                    @foreach ($context['translator_languages'] as $language)
                        <flux:select.option value="{{ $language['id'] }}">{{ $language['name'] }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="translatorName" :label="__('orders.fields.translator_name')" />
                <flux:textarea wire:model="notes" :label="__('orders.fields.notes')" rows="3" />
                <flux:button type="submit" variant="primary" class="w-full">{{ __('orders.center.save') }}</flux:button>
            </form>
        </section>

        <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div>
                <div><h3 class="font-semibold">{{ __('orders.customer.records') }}</h3><p class="mt-1 text-sm text-zinc-500">{{ __('orders.customer.records_description') }}</p></div>
            </div>
            <div class="crm-table-wrap mt-4"><table class="crm-table">
                <thead><tr><th>{{ __('orders.fields.order') }}</th><th>{{ __('orders.fields.status_label') }}</th><th>{{ __('orders.fields.promotion_fee') }}</th><th></th></tr></thead>
                <tbody>
                @forelse ($context['orders'] as $order)
                    <tr wire:key="order-{{ $order['id'] }}">
                        <td><a class="font-semibold text-teal-700 hover:underline" href="{{ route('orders.show', $order['id']) }}" wire:navigate>{{ $order['projectName'] }}</a><div class="text-xs text-zinc-500">₩ {{ number_format($order['amountKrw']) }}</div></td>
                        <td>{{ ['pending' => __('orders.statuses.pending'), 'completed' => __('orders.statuses.completed'), 'cancelled' => __('orders.statuses.cancelled')][$order['status']] ?? $order['status'] }}</td>
                        <td>{{ $order['commissionAmountKrw'] === null ? __('orders.values.empty') : '₩ '.number_format($order['commissionAmountKrw']).' · '.number_format($order['commissionRateBps'] / 100, 2).'%' }}</td>
                        <td>@if ($order['status'] === 'pending')<flux:button wire:click="complete({{ $order['id'] }})" size="sm">{{ __('orders.center.mark_complete') }}</flux:button>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="py-10 text-center text-zinc-500">{{ __('orders.customer.no_orders') }}</td></tr>
                @endforelse
                </tbody>
            </table></div>
        </section>
    </div>
</div>
