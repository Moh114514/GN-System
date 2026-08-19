<div>
    <x-page-back
        :href="$customerId ? route('customers.show', $customerId) : route('customers.index')"
        :label="$customerId ? __('customers.form.back_to_detail') : __('customers.form.back_to_list')"
        class="mb-4"
    />

    <section class="crm-section-header">
        <div>
            <p class="text-xs font-medium text-zinc-400">{{ __('customers.title.form') }}</p>
            <h2 class="mt-1 text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-50">{{ $customerId ? __('customers.form.edit_heading') : __('customers.form.create_heading') }}</h2>
            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ $customerId ? __('customers.form.edit_description') : __('customers.form.create_description') }}</p>
        </div>
    </section>

    <form wire:submit="save" class="space-y-6">
        <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <h3 class="text-lg font-semibold">{{ __('customers.form.sections.basic') }}</h3>
            <div class="mt-5 grid gap-5 md:grid-cols-2">
                <flux:input wire:model="name" :label="__('customers.form.fields.name')" required />
                <flux:select wire:model="gender" :label="__('customers.form.fields.gender')">
                    <flux:select.option value="">{{ __('customers.form.fields.unfilled') }}</flux:select.option>
                    <flux:select.option value="女">{{ __('customers.form.fields.female') }}</flux:select.option>
                    <flux:select.option value="男">{{ __('customers.form.fields.male') }}</flux:select.option>
                    <flux:select.option value="其他">{{ __('customers.form.fields.other') }}</flux:select.option>
                </flux:select>
                <x-date-time-picker wire:model="birthDate" :value="$birthDate" :label="__('customers.form.fields.birth_date')" required />
                <flux:input wire:model="projectIntention" :label="__('customers.form.fields.project_intention')" required />
                <flux:input wire:model="contact" :label="__('customers.form.fields.contact')" required />
                <flux:input wire:model="identityDocument" :label="__('customers.form.fields.identity_document')" required />
            </div>
        </section>

        <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <h3 class="text-lg font-semibold">{{ __('customers.form.sections.source') }}</h3>
            <div class="mt-5 grid gap-5 md:grid-cols-2">
                <flux:select wire:model="sourceAgentId" :label="__('customers.form.fields.source_agent')" required>
                    <flux:select.option value="">{{ __('customers.form.select') }}</flux:select.option>
                    @foreach ($options['agents'] as $agent)
                        <flux:select.option value="{{ $agent['id'] }}">{{ $agent['code'] }} · {{ $agent['name'] }}</flux:select.option>
                    @endforeach
                </flux:select>

                @if ($customerId)
                    <flux:input wire:model="confirmedCode" :label="__('customers.form.fields.customer_code_immutable')" disabled />
                @else
                    <div class="space-y-3 md:col-span-2">
                        <flux:checkbox wire:model.live="automaticCode" :label="__('customers.form.fields.automatic_code')" />
                        <div class="flex items-end gap-3">
                            <div class="flex-1"><flux:input wire:model="confirmedCode" :label="__('customers.form.fields.customer_code')" :disabled="$automaticCode" required /></div>
                            @if ($automaticCode)
                                <flux:button type="button" wire:click="refreshCode">{{ __('customers.form.fields.generate_refresh') }}</flux:button>
                            @endif
                        </div>
                        <flux:checkbox wire:model="codeConfirmed" :label="__('customers.form.fields.confirm_code')" />
                    </div>
                @endif
            </div>
        </section>

        @if (! $customerId)
            <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <h3 class="text-lg font-semibold">{{ __('customers.form.sections.appointment') }}</h3>
                <div class="mt-5 grid gap-5 md:grid-cols-3">
                    <flux:select wire:model="institutionId" :label="__('customers.form.fields.institution')" required>
                        <flux:select.option value="">{{ __('customers.form.select') }}</flux:select.option>
                        @foreach ($options['institutions'] as $institution)
                            <flux:select.option value="{{ $institution['id'] }}">{{ $institution['name'] }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <x-date-time-picker wire:model="arrivalAt" :value="$arrivalAt" mode="datetime" :label="__('customers.form.fields.arrival_at')" required />
                    <flux:input wire:model="translatorName" :label="__('customers.form.fields.translator')" />
                </div>
            </section>
        @endif

        <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <flux:textarea wire:model="notes" :label="__('customers.form.fields.notes')" rows="4" />
            @if ($duplicateIds)
                <div class="mt-4 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
                    {{ __('customers.form.duplicate_notice', ['ids' => implode('、', array_map(fn ($id) => '#'.$id, $duplicateIds))]) }}
                    <div class="mt-2"><flux:checkbox wire:model="duplicateConfirmed" :label="__('customers.form.duplicate_confirm')" /></div>
                </div>
            @endif
            @if ($customerId && ($contact !== $originalContact || $identityDocument !== $originalIdentityDocument))
                <div class="mt-4 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
                    <strong>{{ __('customers.form.sensitive_difference') }}</strong>
                    {{ __('customers.form.sensitive_changes', ['original_contact' => $originalContact, 'contact' => $contact, 'original_document' => $originalIdentityDocument, 'document' => $identityDocument]) }}
                    <div class="mt-2"><flux:checkbox wire:model="sensitiveConfirmation" :label="__('customers.form.sensitive_confirm')" /></div>
                </div>
            @endif

            @if ($errors->any())
                <div class="mt-4 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                    <ul class="list-disc space-y-1 pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                </div>
            @endif
            <div class="mt-5 flex justify-end gap-3">
                <flux:button :href="$customerId ? route('customers.show', $customerId) : route('customers.index')" variant="ghost" wire:navigate>{{ __('customers.form.cancel') }}</flux:button>
                <flux:button type="submit" variant="primary">{{ __('customers.form.save') }}</flux:button>
            </div>
        </section>
    </form>
</div>
