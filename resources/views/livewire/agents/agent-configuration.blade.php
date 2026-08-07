<div>
    <x-page-back :href="route('configuration.index')" :label="__('config.agent.back')" class="mb-4" />
    <section class="mb-6">
        <p class="text-xs font-medium text-zinc-400">{{ __('config.agent.eyebrow') }}</p>
        <h2 class="mt-1 text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-50">{{ __('config.agent.title') }}</h2>
        <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ __('config.agent.description') }}</p>
    </section>


    <div class="space-y-6">
        <section class="grid gap-6 xl:grid-cols-2">
            <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <h3 class="font-semibold">{{ __('config.agent.type_heading') }}</h3>
                <form wire:submit="saveType" class="mt-4 grid gap-3 sm:grid-cols-2">
                    <flux:input wire:model="typeCode" :label="__('config.agent.code')" placeholder="VIP" :title="__('config.agent.type_code_title')" required />
                    <flux:input wire:model="typeName" :label="__('config.agent.name')" :title="__('config.agent.type_name_title')" required />
                    <div class="sm:col-span-2"><flux:textarea wire:model="typeDescription" :label="__('config.agent.description_label')" :title="__('config.agent.type_description_title')" rows="2" /></div>
                    <div class="flex gap-2 sm:col-span-2">
                        <flux:button type="submit">{{ $editingTypeId ? __('config.agent.actions.save_changes') : __('config.agent.actions.create_type') }}</flux:button>
                        @if ($editingTypeId)<flux:button wire:click="cancelTypeEdit" type="button" variant="ghost">{{ __('config.agent.actions.cancel') }}</flux:button>@endif
                    </div>
                </form>
                <div class="crm-table-wrap mt-5"><table class="crm-table">
                    <thead><tr><th>{{ __('config.agent.code') }}</th><th>{{ __('config.agent.name') }}</th><th>{{ __('config.agent.status') }}</th><th></th></tr></thead>
                    <tbody>@foreach ($state['types'] as $type)
                        <tr><td class="font-semibold">{{ $type['code'] }}</td><td>{{ $type['name'] }}</td><td>{{ $type['is_active'] ? __('config.agent.actions.enable') : __('config.agent.actions.disable') }}</td><td><div class="flex gap-1"><flux:button wire:click="editType({{ $type['id'] }})" size="sm" variant="ghost">{{ __('config.agent.actions.edit') }}</flux:button><flux:button wire:click="toggleType({{ $type['id'] }})" size="sm" variant="ghost">{{ $type['is_active'] ? __('config.agent.actions.disable') : __('config.agent.actions.enable') }}</flux:button></div></td></tr>
                    @endforeach</tbody>
                </table></div>
            </div>

            <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <h3 class="font-semibold">{{ __('config.agent.policy_heading') }}</h3>
                <form wire:submit="savePolicy" class="mt-4 flex items-end gap-3">
                    <flux:input wire:model="policyName" :label="__('config.agent.policy_name')" :title="__('config.agent.policy_name_title')" class="flex-1" required />
                    <flux:button type="submit">{{ $editingPolicyId ? __('config.agent.actions.save_changes') : __('config.agent.actions.create_system') }}</flux:button>
                    @if ($editingPolicyId)<flux:button wire:click="cancelPolicyEdit" type="button" variant="ghost">{{ __('config.agent.actions.cancel') }}</flux:button>@endif
                </form>
                <div class="crm-table-wrap mt-5"><table class="crm-table">
                    <thead><tr><th>{{ __('config.agent.system') }}</th><th>{{ __('config.agent.status') }}</th><th></th></tr></thead>
                    <tbody>@forelse ($state['systems'] as $system)
                        <tr><td class="font-semibold">{{ $system['name'] }}</td><td>{{ $system['is_active'] ? __('config.agent.actions.enable') : __('config.agent.actions.disable') }}</td><td><div class="flex gap-1"><flux:button wire:click="editPolicy({{ $system['id'] }})" size="sm" variant="ghost">{{ __('config.agent.actions.edit') }}</flux:button><flux:button wire:click="togglePolicy({{ $system['id'] }})" size="sm" variant="ghost">{{ $system['is_active'] ? __('config.agent.actions.disable') : __('config.agent.actions.enable') }}</flux:button></div></td></tr>
                    @empty<tr><td colspan="3" class="py-8 text-center text-zinc-500">{{ __('config.agent.empty.systems') }}</td></tr>@endforelse</tbody>
                </table></div>
            </div>
        </section>

        <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <h3 class="font-semibold">{{ __('config.agent.grade_heading') }}</h3>
                <flux:select wire:model.live="gradeListSort" :label="__('config.agent.view_sort')" :title="__('config.agent.view_grade_sort_title')" class="min-w-48">
                    <flux:select.option value="configured">{{ __('config.agent.sort_options.configured') }}</flux:select.option>
                    <flux:select.option value="sort_desc">{{ __('config.agent.sort_options.sort_desc') }}</flux:select.option>
                    <flux:select.option value="threshold_asc">{{ __('config.agent.sort_options.threshold_asc') }}</flux:select.option>
                    <flux:select.option value="threshold_desc">{{ __('config.agent.sort_options.threshold_desc') }}</flux:select.option>
                    <flux:select.option value="name_asc">{{ __('config.agent.sort_options.name_asc') }}</flux:select.option>
                    <flux:select.option value="name_desc">{{ __('config.agent.sort_options.name_desc') }}</flux:select.option>
                </flux:select>
            </div>
            <form wire:submit="saveGrade" class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                <flux:select wire:model="gradePolicySystemId" :label="__('config.agent.system')" :title="__('config.agent.grade_system_title')" required><flux:select.option value="">{{ __('config.agent.select') }}</flux:select.option>@foreach ($state['systems'] as $system)<flux:select.option value="{{ $system['id'] }}">{{ $system['name'] }}</flux:select.option>@endforeach</flux:select>
                <flux:input wire:model="gradeName" :label="__('config.agent.grade')" :title="__('config.agent.grade_name_title')" required />
                <flux:input wire:model="gradeThresholdKrw" type="number" min="0" :label="__('config.agent.monthly_threshold')" :title="__('config.agent.grade_threshold_title')" required />
                <flux:input wire:model="gradeSortOrder" type="number" min="0" :label="__('config.agent.sort_order')" :title="__('config.agent.sort_order_title')" required />
                <div class="flex items-end gap-2"><flux:button type="submit" class="flex-1">{{ $editingGradeId ? __('config.agent.actions.save_changes') : __('config.agent.actions.create_grade') }}</flux:button>@if ($editingGradeId)<flux:button wire:click="cancelGradeEdit" type="button" variant="ghost">{{ __('config.agent.actions.cancel') }}</flux:button>@endif</div>
            </form>
            <div class="crm-table-wrap mt-5"><table class="crm-table">
                <thead><tr><th>{{ __('config.agent.system') }}</th><th>{{ __('config.agent.grade') }}</th><th>{{ __('config.agent.monthly_threshold') }}</th><th title="{{ __('config.agent.table_sort_title') }}">{{ __('config.agent.sort_order') }}</th><th>{{ __('config.agent.status') }}</th><th></th></tr></thead>
                <tbody>@forelse ($state['grades'] as $grade)
                    @php($system = collect($state['systems'])->firstWhere('id', $grade['policy_system_id']))
                    <tr><td>{{ $system['name'] ?? __('config.agent.unknown') }}</td><td class="font-semibold">{{ $grade['name'] }}</td><td>₩ {{ number_format($grade['monthly_threshold_krw']) }}</td><td>{{ $grade['sort_order'] }}</td><td>{{ $grade['is_active'] ? __('config.agent.actions.enable') : __('config.agent.actions.disable') }}</td><td><div class="flex gap-1"><flux:button wire:click="editGrade({{ $grade['id'] }})" size="sm" variant="ghost">{{ __('config.agent.actions.edit') }}</flux:button><flux:button wire:click="toggleGrade({{ $grade['id'] }})" size="sm" variant="ghost">{{ $grade['is_active'] ? __('config.agent.actions.disable') : __('config.agent.actions.enable') }}</flux:button></div></td></tr>
                @empty<tr><td colspan="6" class="py-8 text-center text-zinc-500">{{ __('config.agent.empty.grades') }}</td></tr>@endforelse</tbody>
            </table></div>
        </section>

        <section class="grid gap-6 xl:grid-cols-2">
            <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <h3 class="font-semibold">{{ __('config.agent.rate_heading') }}</h3>
                    <flux:select wire:model.live="ruleListSort" :label="__('config.agent.view_sort')" :title="__('config.agent.view_rate_sort_title')" class="min-w-44">
                        <flux:select.option value="effective_desc">{{ __('config.agent.sort_options.effective_desc') }}</flux:select.option>
                        <flux:select.option value="effective_asc">{{ __('config.agent.sort_options.effective_asc') }}</flux:select.option>
                        <flux:select.option value="rate_desc">{{ __('config.agent.sort_options.rate_desc') }}</flux:select.option>
                        <flux:select.option value="rate_asc">{{ __('config.agent.sort_options.rate_asc') }}</flux:select.option>
                        <flux:select.option value="grade_asc">{{ __('config.agent.sort_options.grade_asc') }}</flux:select.option>
                        <flux:select.option value="institution_asc">{{ __('config.agent.sort_options.institution_asc') }}</flux:select.option>
                    </flux:select>
                </div>
                <form wire:submit="saveRule" class="mt-4 space-y-3">
                    <flux:select wire:model="ruleGradeId" :label="__('config.agent.grade')" :title="__('config.agent.rule_grade_title')" required><flux:select.option value="">{{ __('config.agent.select') }}</flux:select.option>@foreach ($state['grades'] as $grade)<flux:select.option value="{{ $grade['id'] }}">{{ $grade['name'] }}</flux:select.option>@endforeach</flux:select>
                    <flux:select wire:model="ruleInstitutionId" :label="__('config.agent.institution')" :title="__('config.agent.rule_institution_title')" required><flux:select.option value="">{{ __('config.agent.select') }}</flux:select.option>@foreach ($state['institutions'] as $institution)<flux:select.option value="{{ $institution['id'] }}">{{ $institution['name'] }}</flux:select.option>@endforeach</flux:select>
                    <div class="grid gap-3 sm:grid-cols-2"><flux:input wire:model="ruleRateBps" type="number" min="0" max="10000" :label="__('config.agent.rate_bps')" :title="__('config.agent.rate_bps_title')"/><flux:input wire:model="ruleEffectiveMonth" type="date" :label="__('config.agent.effective_month')" :title="__('config.agent.rule_effective_month_title')" required /></div>
                    <flux:button type="submit" variant="primary">{{ __('config.agent.actions.save_rate') }}</flux:button>
                </form>
                <div class="crm-table-wrap mt-5"><table class="crm-table"><thead><tr><th>{{ __('config.agent.grade') }}/{{ __('config.agent.institution') }}</th><th>{{ __('config.agent.rate') }}</th><th>{{ __('config.agent.effective_month') }}</th></tr></thead><tbody>
                    @forelse ($state['rules'] as $rule)
                        @php($grade = collect($state['grades'])->firstWhere('id', $rule['policy_grade_id']))
                        @php($institution = collect($state['institutions'])->firstWhere('id', $rule['institution_id']))
                        <tr><td>{{ $grade['name'] ?? __('config.agent.unknown_grade') }}<div class="text-xs text-zinc-500">{{ $institution['name'] ?? __('config.agent.unknown_institution') }}</div></td><td>{{ number_format($rule['rate_bps'] / 100, 2) }}%</td><td>{{ \Carbon\CarbonImmutable::parse($rule['effective_month'])->format('Y-m') }}</td></tr>
                    @empty<tr><td colspan="3" class="py-8 text-center text-zinc-500">{{ __('config.agent.empty.rates') }}</td></tr>@endforelse
                </tbody></table></div>
            </div>

            <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <h3 class="font-semibold">{{ __('config.agent.override_heading') }}</h3>
                    <flux:select wire:model.live="overrideListSort" :label="__('config.agent.view_sort')" :title="__('config.agent.view_override_sort_title')" class="min-w-44">
                        <flux:select.option value="effective_desc">{{ __('config.agent.sort_options.effective_desc') }}</flux:select.option>
                        <flux:select.option value="effective_asc">{{ __('config.agent.sort_options.effective_asc') }}</flux:select.option>
                        <flux:select.option value="rate_desc">{{ __('config.agent.sort_options.rate_desc') }}</flux:select.option>
                        <flux:select.option value="rate_asc">{{ __('config.agent.sort_options.rate_asc') }}</flux:select.option>
                        <flux:select.option value="agent_asc">{{ __('config.agent.sort_options.agent_asc') }}</flux:select.option>
                        <flux:select.option value="institution_asc">{{ __('config.agent.sort_options.institution_scope_asc') }}</flux:select.option>
                    </flux:select>
                </div>
                <form wire:submit="saveOverride" class="mt-4 space-y-3">
                    <flux:select wire:model="overrideAgentId" :label="__('config.agent.agent')" :title="__('config.agent.override_agent_title')" required><flux:select.option value="">{{ __('config.agent.select') }}</flux:select.option>@foreach ($state['agents'] as $agent)<flux:select.option value="{{ $agent['id'] }}">{{ $agent['code'] }} · {{ $agent['name'] }}</flux:select.option>@endforeach</flux:select>
                    <flux:select wire:model="overrideInstitutionId" :label="__('config.agent.institution_scope')" :title="__('config.agent.override_institution_title')"><flux:select.option value="">{{ __('config.agent.all_institutions') }}</flux:select.option>@foreach ($state['institutions'] as $institution)<flux:select.option value="{{ $institution['id'] }}">{{ $institution['name'] }}</flux:select.option>@endforeach</flux:select>
                    <div class="grid gap-3 sm:grid-cols-2"><flux:input wire:model="overrideRateBps" type="number" min="0" max="10000" :label="__('config.agent.rate_bps')" :title="__('config.agent.override_rate_bps_title')"/><flux:input wire:model="overrideEffectiveMonth" type="date" :label="__('config.agent.effective_month')" :title="__('config.agent.override_effective_month_title')" required /></div>
                    <flux:textarea wire:model="overrideReason" :label="__('config.agent.override_reason')" :title="__('config.agent.override_reason_title')" rows="2" required />
                    <flux:button type="submit" variant="primary">{{ __('config.agent.actions.save_override') }}</flux:button>
                </form>
                <div class="crm-table-wrap mt-5"><table class="crm-table"><thead><tr><th>{{ __('config.agent.agent') }}/{{ __('config.agent.institution_scope') }}</th><th>{{ __('config.agent.rate') }}</th><th>{{ __('config.agent.effective_month') }}</th></tr></thead><tbody>
                    @forelse ($state['overrides'] as $override)
                        @php($agent = collect($state['agents'])->firstWhere('id', $override['agent_id']))
                        @php($institution = $override['institution_id'] ? collect($state['institutions'])->firstWhere('id', $override['institution_id']) : null)
                        <tr><td><span class="font-semibold">{{ $agent['name'] ?? __('config.agent.unknown_agent') }}</span><div class="text-xs text-zinc-500">{{ $institution['name'] ?? __('config.agent.all_institutions') }} · {{ $override['reason'] }}</div></td><td>{{ number_format($override['rate_bps'] / 100, 2) }}%</td><td>{{ \Carbon\CarbonImmutable::parse($override['effective_from'])->format('Y-m') }}</td></tr>
                    @empty<tr><td colspan="3" class="py-8 text-center text-zinc-500">{{ __('config.agent.empty.overrides') }}</td></tr>@endforelse
                </tbody></table></div>
            </div>
        </section>
    </div>
</div>
