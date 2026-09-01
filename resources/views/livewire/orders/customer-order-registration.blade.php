<div wire:key="customer-order-registration-{{ $customerId }}">
    <flux:modal name="customer-order-registration" class="max-w-3xl" @close="resetRegistration">
        @if ($status === 'success' && $successResult)
            <div class="space-y-6">
                <div>
                    <div class="flex items-center gap-2 text-teal-700 dark:text-teal-300">
                        <span class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-teal-100 text-lg dark:bg-teal-950/50">✓</span>
                        <flux:heading size="lg">{{ __('orders.registration.success_title') }}</flux:heading>
                    </div>
                    <flux:subheading class="mt-2">{{ __('orders.registration.success_description') }}</flux:subheading>
                </div>

                <dl class="grid gap-4 rounded-2xl bg-zinc-50 p-5 sm:grid-cols-2 dark:bg-zinc-800/60">
                    <div><dt class="text-xs text-zinc-500">{{ __('orders.fields.order') }}</dt><dd class="mt-1 font-semibold">#{{ $successResult['id'] }} <span class="crm-pill tone-green ml-2">{{ __('orders.statuses.completed') }}</span></dd></div>
                    <div><dt class="text-xs text-zinc-500">{{ __('orders.fields.treatment_project') }}</dt><dd class="mt-1 font-medium">{{ $successResult['project_name'] }}</dd></div>
                    <div><dt class="text-xs text-zinc-500">{{ __('orders.fields.institution') }}</dt><dd class="mt-1 font-medium">{{ $successResult['institution'] }}</dd></div>
                    <div><dt class="text-xs text-zinc-500">{{ __('orders.fields.occurred_on') }}</dt><dd class="mt-1 font-medium">{{ $successResult['occurred_on'] ?: __('orders.values.empty') }}</dd></div>
                    <div><dt class="text-xs text-zinc-500">{{ __('orders.fields.transaction_amount') }}</dt><dd class="mt-1 font-medium">₩ {{ number_format((int) $successResult['amount_krw']) }}</dd></div>
                </dl>

                <div class="flex justify-end">
                    <flux:modal.close>
                        <flux:button wire:click="completeRegistration" variant="primary">{{ __('orders.registration.done') }}</flux:button>
                    </flux:modal.close>
                </div>
            </div>
        @else
            <form wire:submit="uploadReturn" class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('orders.registration.title') }}</flux:heading>
                    <flux:subheading class="mt-2">{{ __('orders.registration.description') }}</flux:subheading>
                </div>

                <section class="rounded-2xl border border-zinc-200 p-4 dark:border-zinc-700">
                    <h3 class="text-sm font-semibold">{{ __('orders.registration.customer_context') }}</h3>
                    <dl class="mt-4 grid gap-4 sm:grid-cols-2">
                        <div><dt class="text-xs text-zinc-500">{{ __('orders.fields.customer') }}</dt><dd class="mt-1 font-semibold">{{ $context['customer']['name'] }} · {{ $context['customer']['code'] }}</dd></div>
                        <div><dt class="text-xs text-zinc-500">{{ __('customers.detail.profile.agent') }}</dt><dd class="mt-1 font-medium">{{ $context['agent']['name'] ?? __('customers.fallback.unknown_agent') }}</dd></div>
                        <div><dt class="text-xs text-zinc-500">{{ __('orders.registration.status') }}</dt><dd class="mt-1 font-medium">{{ $context['customer']['current_status'] ?: __('customers.fallback.unset') }}</dd></div>
                        <div><dt class="text-xs text-zinc-500">{{ __('customers.detail.profile.arrived_at') }}</dt><dd class="mt-1 font-medium">{{ $context['customer']['arrived_at'] ?: __('customers.fallback.unset') }}</dd></div>
                    </dl>
                </section>

                @if (! ($context['can_register'] ?? false))
                    <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-200">
                        {{ __('orders.errors.customer_not_arrived') }}
                    </div>
                @endif

                <section class="space-y-3">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <h3 class="text-sm font-semibold">{{ __('orders.registration.form_title') }}</h3>
                            <p class="mt-1 text-xs text-zinc-500">{{ __('orders.registration.form_hint') }}</p>
                        </div>
                        @if (($context['institution_locked'] ?? false) && ! $institutionPickerOpen)
                            <flux:button type="button" wire:click="showInstitutionPicker" variant="ghost" size="sm">{{ __('orders.registration.change_institution') }}</flux:button>
                        @endif
                    </div>

                    @if (($context['institution_locked'] ?? false) && ! $institutionPickerOpen)
                        <div class="rounded-xl bg-teal-50 px-4 py-3 text-sm dark:bg-teal-950/30">
                            <span class="font-medium">{{ $context['institution']['name'] }}</span>
                            <span class="ml-2 text-xs text-zinc-500">{{ $context['institution']['code'] }}</span>
                        </div>
                    @else
                        <flux:select wire:model="institutionId" :label="__('orders.fields.institution')" required>
                            <flux:select.option value="">{{ __('orders.fields.select') }}</flux:select.option>
                            @foreach ($context['institutions'] as $institution)
                                <flux:select.option value="{{ $institution['id'] }}">{{ $institution['code'] }} · {{ $institution['name'] }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    @endif
                    @error('institutionId') <p class="text-sm text-red-600">{{ $message }}</p> @enderror

                    <div class="flex flex-wrap items-center gap-3">
                        <flux:button type="button" wire:click="downloadTemplate" variant="ghost" icon="arrow-down-tray" :disabled="! ($context['can_register'] ?? false)">{{ __('orders.registration.download_template') }}</flux:button>
                        <span class="text-xs text-zinc-500">{{ __('orders.registration.download_hint') }}</span>
                    </div>
                </section>

                <section class="space-y-3">
                    <div>
                        <h3 class="text-sm font-semibold">{{ __('orders.registration.upload_title') }}</h3>
                        <p class="mt-1 text-xs text-zinc-500">{{ __('orders.registration.upload_description') }}</p>
                    </div>
                    <label class="flex cursor-pointer flex-col items-center justify-center rounded-2xl border-2 border-dashed border-zinc-300 px-6 py-8 text-center transition hover:border-teal-500 hover:bg-teal-50/50 dark:border-zinc-600 dark:hover:bg-teal-950/20">
                        <span class="text-sm font-medium">{{ __('orders.registration.drop_file') }}</span>
                        <span class="mt-1 text-xs text-zinc-500">.xlsx / .xlsm / .xls · {{ __('orders.registration.max_file') }}</span>
                        <input type="file" wire:model="upload" accept=".xlsx,.xlsm,.xls" class="sr-only" />
                    </label>
                    @if ($upload)
                        <p class="text-sm text-zinc-600 dark:text-zinc-300">{{ $upload->getClientOriginalName() }}</p>
                    @endif
                    @error('upload') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                    @if ($status === 'error' && $errorMessage && ! $errors->has('upload'))
                        <p class="text-sm text-red-600">{{ $errorMessage }}</p>
                    @endif
                </section>

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button type="button" variant="ghost">{{ __('orders.registration.cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button type="submit" variant="primary" :disabled="! ($context['can_register'] ?? false)" wire:loading.attr="disabled">{{ __('orders.registration.upload_submit') }}</flux:button>
                </div>
            </form>
        @endif
    </flux:modal>
</div>
