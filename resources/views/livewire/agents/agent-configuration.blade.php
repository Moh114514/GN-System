<div>
    <x-page-back :href="route('configuration.index')" label="返回配置中心" class="mb-4" />
    <section class="mb-6">
        <p class="crm-eyebrow">配置中心 · 代理商配置</p>
        <h2>代理商与推广费配置</h2>
        <p>配置类型、政策等级和按月生效的机构费率；历史月份与推广费快照不会被改写。</p>
    </section>

    @if (session('status'))
        <div class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>
    @endif
    @error('configuration') <div class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $message }}</div> @enderror

    <div class="space-y-6">
        <section class="grid gap-6 xl:grid-cols-2">
            <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <h3 class="font-semibold">代理商类型代码</h3>
                <form wire:submit="saveType" class="mt-4 grid gap-3 sm:grid-cols-2">
                    <flux:input wire:model="typeCode" label="代码" placeholder="VIP" required />
                    <flux:input wire:model="typeName" label="名称" required />
                    <div class="sm:col-span-2"><flux:textarea wire:model="typeDescription" label="说明" rows="2" /></div>
                    <div class="flex gap-2 sm:col-span-2">
                        <flux:button type="submit">{{ $editingTypeId ? '保存修改' : '新增类型' }}</flux:button>
                        @if ($editingTypeId)<flux:button wire:click="cancelTypeEdit" type="button" variant="ghost">取消</flux:button>@endif
                    </div>
                </form>
                <div class="crm-table-wrap mt-5"><table class="crm-table">
                    <thead><tr><th>代码</th><th>名称</th><th>状态</th><th></th></tr></thead>
                    <tbody>@foreach ($state['types'] as $type)
                        <tr><td class="font-semibold">{{ $type['code'] }}</td><td>{{ $type['name'] }}</td><td>{{ $type['is_active'] ? '启用' : '停用' }}</td><td><div class="flex gap-1"><flux:button wire:click="editType({{ $type['id'] }})" size="sm" variant="ghost">编辑</flux:button><flux:button wire:click="toggleType({{ $type['id'] }})" size="sm" variant="ghost">{{ $type['is_active'] ? '停用' : '启用' }}</flux:button></div></td></tr>
                    @endforeach</tbody>
                </table></div>
            </div>

            <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <h3 class="font-semibold">政策体系</h3>
                <form wire:submit="savePolicy" class="mt-4 flex items-end gap-3">
                    <flux:input wire:model="policyName" label="政策体系名称" class="flex-1" required />
                    <flux:button type="submit">{{ $editingPolicyId ? '保存修改' : '新增体系' }}</flux:button>
                    @if ($editingPolicyId)<flux:button wire:click="cancelPolicyEdit" type="button" variant="ghost">取消</flux:button>@endif
                </form>
                <div class="crm-table-wrap mt-5"><table class="crm-table">
                    <thead><tr><th>体系</th><th>状态</th><th></th></tr></thead>
                    <tbody>@forelse ($state['systems'] as $system)
                        <tr><td class="font-semibold">{{ $system['name'] }}</td><td>{{ $system['is_active'] ? '启用' : '停用' }}</td><td><div class="flex gap-1"><flux:button wire:click="editPolicy({{ $system['id'] }})" size="sm" variant="ghost">编辑</flux:button><flux:button wire:click="togglePolicy({{ $system['id'] }})" size="sm" variant="ghost">{{ $system['is_active'] ? '停用' : '启用' }}</flux:button></div></td></tr>
                    @empty<tr><td colspan="3" class="py-8 text-center text-zinc-500">请先建立政策体系。</td></tr>@endforelse</tbody>
                </table></div>
            </div>
        </section>

        <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <h3 class="font-semibold">政策等级</h3>
            <form wire:submit="saveGrade" class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                <flux:select wire:model="gradePolicySystemId" label="所属体系" required><flux:select.option value="">请选择</flux:select.option>@foreach ($state['systems'] as $system)<flux:select.option value="{{ $system['id'] }}">{{ $system['name'] }}</flux:select.option>@endforeach</flux:select>
                <flux:input wire:model="gradeName" label="等级名称" required />
                <flux:input wire:model="gradeThresholdKrw" type="number" min="0" label="月业绩门槛（KRW）" required />
                <flux:input wire:model="gradeSortOrder" type="number" min="0" label="排序" required />
                <div class="flex items-end gap-2"><flux:button type="submit" class="flex-1">{{ $editingGradeId ? '保存修改' : '新增等级' }}</flux:button>@if ($editingGradeId)<flux:button wire:click="cancelGradeEdit" type="button" variant="ghost">取消</flux:button>@endif</div>
            </form>
            <div class="crm-table-wrap mt-5"><table class="crm-table">
                <thead><tr><th>体系</th><th>等级</th><th>月门槛</th><th>状态</th><th></th></tr></thead>
                <tbody>@forelse ($state['grades'] as $grade)
                    @php($system = collect($state['systems'])->firstWhere('id', $grade['policy_system_id']))
                    <tr><td>{{ $system['name'] ?? '未知' }}</td><td class="font-semibold">{{ $grade['name'] }}</td><td>₩ {{ number_format($grade['monthly_threshold_krw']) }}</td><td>{{ $grade['is_active'] ? '启用' : '停用' }}</td><td><div class="flex gap-1"><flux:button wire:click="editGrade({{ $grade['id'] }})" size="sm" variant="ghost">编辑</flux:button><flux:button wire:click="toggleGrade({{ $grade['id'] }})" size="sm" variant="ghost">{{ $grade['is_active'] ? '停用' : '启用' }}</flux:button></div></td></tr>
                @empty<tr><td colspan="5" class="py-8 text-center text-zinc-500">尚未配置等级。</td></tr>@endforelse</tbody>
            </table></div>
        </section>

        <section class="grid gap-6 xl:grid-cols-2">
            <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <h3 class="font-semibold">等级机构费率</h3>
                <form wire:submit="saveRule" class="mt-4 space-y-3">
                    <flux:select wire:model="ruleGradeId" label="政策等级" required><flux:select.option value="">请选择</flux:select.option>@foreach ($state['grades'] as $grade)<flux:select.option value="{{ $grade['id'] }}">{{ $grade['name'] }}</flux:select.option>@endforeach</flux:select>
                    <flux:select wire:model="ruleInstitutionId" label="机构" required><flux:select.option value="">请选择</flux:select.option>@foreach ($state['institutions'] as $institution)<flux:select.option value="{{ $institution['id'] }}">{{ $institution['name'] }}</flux:select.option>@endforeach</flux:select>
                    <div class="grid gap-3 sm:grid-cols-2"><flux:input wire:model="ruleRateBps" type="number" min="0" max="10000" label="费率基点（100点=1%）"/><flux:input wire:model="ruleEffectiveMonth" type="date" label="生效月份" required /></div>
                    <flux:button type="submit" variant="primary">保存机构费率</flux:button>
                </form>
                <div class="crm-table-wrap mt-5"><table class="crm-table"><thead><tr><th>等级/机构</th><th>费率</th><th>生效月</th></tr></thead><tbody>
                    @forelse ($state['rules'] as $rule)
                        @php($grade = collect($state['grades'])->firstWhere('id', $rule['policy_grade_id']))
                        @php($institution = collect($state['institutions'])->firstWhere('id', $rule['institution_id']))
                        <tr><td>{{ $grade['name'] ?? '未知等级' }}<div class="text-xs text-zinc-500">{{ $institution['name'] ?? '未知机构' }}</div></td><td>{{ number_format($rule['rate_bps'] / 100, 2) }}%</td><td>{{ \Carbon\CarbonImmutable::parse($rule['effective_month'])->format('Y-m') }}</td></tr>
                    @empty<tr><td colspan="3" class="py-8 text-center text-zinc-500">尚未配置费率。</td></tr>@endforelse
                </tbody></table></div>
            </div>

            <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <h3 class="font-semibold">代理商特批</h3>
                <form wire:submit="saveOverride" class="mt-4 space-y-3">
                    <flux:select wire:model="overrideAgentId" label="代理商" required><flux:select.option value="">请选择</flux:select.option>@foreach ($state['agents'] as $agent)<flux:select.option value="{{ $agent['id'] }}">{{ $agent['code'] }} · {{ $agent['name'] }}</flux:select.option>@endforeach</flux:select>
                    <flux:select wire:model="overrideInstitutionId" label="机构范围"><flux:select.option value="">全部机构</flux:select.option>@foreach ($state['institutions'] as $institution)<flux:select.option value="{{ $institution['id'] }}">{{ $institution['name'] }}</flux:select.option>@endforeach</flux:select>
                    <div class="grid gap-3 sm:grid-cols-2"><flux:input wire:model="overrideRateBps" type="number" min="0" max="10000" label="费率基点（100点=1%）"/><flux:input wire:model="overrideEffectiveMonth" type="date" label="生效月份" required /></div>
                    <flux:textarea wire:model="overrideReason" label="特批原因" rows="2" required />
                    <flux:button type="submit" variant="primary">保存特批</flux:button>
                </form>
                <div class="crm-table-wrap mt-5"><table class="crm-table"><thead><tr><th>代理商/范围</th><th>费率</th><th>生效月</th></tr></thead><tbody>
                    @forelse ($state['overrides'] as $override)
                        @php($agent = collect($state['agents'])->firstWhere('id', $override['agent_id']))
                        @php($institution = $override['institution_id'] ? collect($state['institutions'])->firstWhere('id', $override['institution_id']) : null)
                        <tr><td><span class="font-semibold">{{ $agent['name'] ?? '未知代理商' }}</span><div class="text-xs text-zinc-500">{{ $institution['name'] ?? '全部机构' }} · {{ $override['reason'] }}</div></td><td>{{ number_format($override['rate_bps'] / 100, 2) }}%</td><td>{{ \Carbon\CarbonImmutable::parse($override['effective_from'])->format('Y-m') }}</td></tr>
                    @empty<tr><td colspan="3" class="py-8 text-center text-zinc-500">尚未配置特批。</td></tr>@endforelse
                </tbody></table></div>
            </div>
        </section>
    </div>
</div>
