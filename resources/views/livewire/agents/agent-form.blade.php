<div>
    <x-page-back
        :href="$agentId ? route('agents.show', $agentId) : route('agents.index')"
        :label="$agentId ? __('agents.form.back_detail') : __('agents.form.back_list')"
        class="mb-4"
    />

    <section class="mb-6">
        <p class="text-xs font-medium text-zinc-400">{{ __('agents.form.profile') }}</p>
        <h2 class="mt-1 text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-50">{{ $agentId ? __('agents.form.edit') : __('agents.form.create') }}</h2>
        <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ $agentId ? __('agents.form.edit_description') : __('agents.form.create_description') }}</p>
    </section>

    <form wire:submit="save" class="space-y-6">
        <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <h3 class="text-lg font-semibold">{{ __('agents.form.basic') }}</h3>
            <div class="mt-5 grid gap-5 md:grid-cols-2">
                <flux:select wire:model="typeCodeId" :label="__('agents.form.type')" required>
                    <flux:select.option value="">{{ __('agents.form.select') }}</flux:select.option>
                    @foreach ($options['types'] as $type)
                        <flux:select.option value="{{ $type['id'] }}">{{ $type['code'] }} · {{ $type['name'] }}</flux:select.option>
                    @endforeach
                </flux:select>
                @if ($agentId)
                    <flux:input wire:model="code" :label="__('agents.form.code_immutable')" readonly />
                @else
                    <flux:input wire:model="codePrefix" :label="__('agents.form.code_prefix')" :description="__('agents.form.code_description')" required />
                @endif
                <flux:input wire:model="name" :label="__('agents.form.name')" required />
                <flux:input wire:model="businessRole" :label="__('agents.form.business_role')" />
                <flux:input wire:model="contactName" :label="__('agents.form.contact_name')" />
                <flux:input wire:model="contactValue" :label="__('agents.form.contact_value')" />
            </div>
        </section>

        <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <h3 class="text-lg font-semibold">{{ __('agents.form.cooperation') }}</h3>
            <div class="mt-5 grid gap-5 md:grid-cols-2">
                <flux:select wire:model="policyGradeId" :label="__('agents.form.policy_grade')" @if($agentId === null || $hasCurrentGrade) required @endif>
                    <flux:select.option value="">{{ __('agents.form.select') }}</flux:select.option>
                    @foreach ($options['systems'] as $system)
                        @foreach (collect($options['grades'])->where('policy_system_id', $system['id']) as $grade)
                            <flux:select.option value="{{ $grade['id'] }}">{{ $system['name'] }} · {{ $grade['name'] }}</flux:select.option>
                        @endforeach
                    @endforeach
                </flux:select>
                <flux:select wire:model.live="cooperationStatus" :label="__('agents.form.status')" required>
                    <flux:select.option value="active">{{ __('agents.form.active') }}</flux:select.option>
                    <flux:select.option value="paused">{{ __('agents.form.paused') }}</flux:select.option>
                    <flux:select.option value="terminated">{{ __('agents.form.terminated') }}</flux:select.option>
                </flux:select>
                <x-localized-date-picker wire:model="cooperationStartedOn" :value="$cooperationStartedOn" :label="__('agents.form.started')" required />
                @if ($cooperationStatus === 'terminated')
                    <x-localized-date-picker wire:model="cooperationEndedOn" :value="$cooperationEndedOn" :label="__('agents.form.ended')" required />
                @endif
                <div class="md:col-span-2"><flux:textarea wire:model="notes" :label="__('agents.form.notes')" rows="4" /></div>
            </div>
        </section>

        @if ($agentId && ! $hasCurrentGrade)
            <section class="rounded-2xl border border-amber-300 bg-amber-50 p-6">
                <h3 class="text-lg font-semibold text-amber-900">{{ __('agents.form.grade_correction') }}</h3>
                <p class="mt-1 text-sm text-amber-800">{{ __('agents.form.missing_grade_notice') }}</p>
                <p class="mt-1 text-sm text-amber-800">{{ __('agents.form.grade_correction_description') }}</p>
                <div class="mt-5 grid gap-5 md:grid-cols-2">
                    <flux:select wire:model="correctionGradeId" :label="__('agents.form.correction_grade')" required>
                        <flux:select.option value="">{{ __('agents.form.select') }}</flux:select.option>
                        @foreach ($options['systems'] as $system)
                            @foreach (collect($options['grades'])->where('policy_system_id', $system['id']) as $grade)
                                <flux:select.option value="{{ $grade['id'] }}">{{ $system['name'] }} · {{ $grade['name'] }}</flux:select.option>
                            @endforeach
                        @endforeach
                    </flux:select>
                    <x-localized-date-picker wire:model="correctionEffectiveMonth" :value="$correctionEffectiveMonth" :label="__('agents.form.correction_effective_month')" required />
                    <div class="md:col-span-2"><flux:textarea wire:model="correctionReason" :label="__('agents.form.correction_reason')" rows="3" required /></div>
                    <div class="md:col-span-2"><flux:checkbox wire:model="confirmCorrection" :label="__('agents.form.correction_confirm')" /></div>
                </div>
                <flux:button type="button" wire:click="saveGradeCorrection" variant="primary" class="mt-4">{{ __('agents.form.save_correction') }}</flux:button>
            </section>
        @endif

        <div class="flex justify-end"><flux:button type="submit" variant="primary">{{ __('agents.form.save') }}</flux:button></div>
    </form>
</div>
