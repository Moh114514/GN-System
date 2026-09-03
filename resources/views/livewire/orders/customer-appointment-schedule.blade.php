<div wire:key="customer-appointment-schedule-{{ $customerId }}">
    <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h3 class="font-semibold">{{ __('orders.appointment_schedule.title') }}</h3>
                <p class="mt-1 text-sm text-zinc-500">{{ __('orders.appointment_schedule.description') }}</p>
            </div>
            @if (($context['appointment'] ?? null) && ($context['can_edit'] ?? false))
                <flux:modal.trigger name="{{ $modalName }}">
                    <flux:button type="button" size="sm" variant="ghost">{{ __('orders.appointment_schedule.edit') }}</flux:button>
                </flux:modal.trigger>
            @endif
        </div>

        @if ($context['appointment'] ?? null)
            <dl class="mt-4 grid gap-4 sm:grid-cols-3">
                <div><dt class="text-xs text-zinc-500">{{ __('orders.appointment_schedule.expected') }}</dt><dd class="mt-1 font-semibold">{{ $context['appointment']['scheduled_at'] ? \Carbon\CarbonImmutable::parse($context['appointment']['scheduled_at'])->format('Y-m-d H:i') : __('orders.values.empty') }}</dd></div>
                <div><dt class="text-xs text-zinc-500">{{ __('orders.appointment_schedule.actual') }}</dt><dd class="mt-1 font-medium">{{ $context['customer']['arrived_at'] ?: __('orders.values.empty') }}</dd></div>
                <div><dt class="text-xs text-zinc-500">{{ __('orders.fields.institution') }}</dt><dd class="mt-1 font-medium">{{ $context['institution']['name'] ?? __('orders.values.unknown_institution') }}</dd></div>
            </dl>
        @else
            <p class="mt-4 text-sm text-zinc-500">{{ __('orders.appointment_schedule.empty') }}</p>
        @endif
    </section>

    @if ($context['appointment'] ?? null)
        <flux:modal name="{{ $modalName }}" class="max-w-lg">
            <form wire:submit="save" class="space-y-5">
                <div>
                    <flux:heading size="lg">{{ __('orders.appointment_schedule.edit_title') }}</flux:heading>
                    <flux:subheading class="mt-2">{{ __('orders.appointment_schedule.edit_description') }}</flux:subheading>
                </div>
                <div class="rounded-xl bg-zinc-50 p-4 text-sm dark:bg-zinc-800/60">
                    <span class="font-medium">{{ $context['customer']['name'] }}</span>
                    <span class="ml-2 text-zinc-500">{{ $context['institution']['name'] ?? __('orders.values.unknown_institution') }}</span>
                </div>
                <x-date-time-picker wire:model="scheduledAt" :value="$scheduledAt" mode="datetime" :label="__('orders.appointment_schedule.expected')" required :disabled="! $canEdit" />
                @error('scheduledAt') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button type="button" variant="ghost">{{ __('orders.registration.cancel') }}</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary" :disabled="! $canEdit">{{ __('orders.appointment_schedule.save') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif
</div>
