<div>
    <x-page-back :href="route('configuration.index')" label="返回配置中心" class="mb-4" />
    <section class="mb-6">
        <p class="text-xs font-medium text-zinc-400">配置中心 · 代理商配置</p>
        <h2 class="mt-1 text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-50">代理商与推广费配置</h2>
        <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">配置类型、政策等级和按月生效的机构费率；历史月份与推广费快照不会被改写。</p>
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
                    <flux:input wire:model="typeCode" label="代码" placeholder="VIP" title="2–4 位字母或数字；代码会成为代理商编号的固定后缀，保存后应保持稳定。" required />
                    <flux:input wire:model="typeName" label="名称" title="面向用户显示的代理类型名称，例如机构、个体户或韩国代理。" required />
                    <div class="sm:col-span-2"><flux:textarea wire:model="typeDescription" label="说明" title="说明该代理类型适用的业务对象和使用边界。" rows="2" /></div>
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
                    <flux:input wire:model="policyName" label="政策体系名称" title="用于归组多个政策等级；等级和费率配置会引用所属体系。" class="flex-1" required />
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
            <div class="flex flex-wrap items-end justify-between gap-3">
                <h3 class="font-semibold">政策等级</h3>
                <flux:select wire:model.live="gradeListSort" label="查看排序" title="只改变当前列表的查看顺序，不修改等级本身的业务排序。" class="min-w-48">
                    <flux:select.option value="configured">体系及业务排序（默认）</flux:select.option>
                    <flux:select.option value="sort_desc">业务排序：大到小</flux:select.option>
                    <flux:select.option value="threshold_asc">月门槛：低到高</flux:select.option>
                    <flux:select.option value="threshold_desc">月门槛：高到低</flux:select.option>
                    <flux:select.option value="name_asc">等级名称：升序</flux:select.option>
                    <flux:select.option value="name_desc">等级名称：降序</flux:select.option>
                </flux:select>
            </div>
            <form wire:submit="saveGrade" class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                <flux:select wire:model="gradePolicySystemId" label="所属体系" title="选择该等级归属的政策体系。" required><flux:select.option value="">请选择</flux:select.option>@foreach ($state['systems'] as $system)<flux:select.option value="{{ $system['id'] }}">{{ $system['name'] }}</flux:select.option>@endforeach</flux:select>
                <flux:input wire:model="gradeName" label="等级名称" title="面向用户显示的等级名称，同一体系内应避免重名。" required />
                <flux:input wire:model="gradeThresholdKrw" type="number" min="0" label="月业绩门槛（KRW）" title="代理商当月业绩达到该金额后，可形成对应的下月等级建议。" required />
                <flux:input wire:model="gradeSortOrder" type="number" min="0" label="排序" title="数字越小，在所属体系和默认列表中越靠前；数字相同时按等级名称稳定排序。" required />
                <div class="flex items-end gap-2"><flux:button type="submit" class="flex-1">{{ $editingGradeId ? '保存修改' : '新增等级' }}</flux:button>@if ($editingGradeId)<flux:button wire:click="cancelGradeEdit" type="button" variant="ghost">取消</flux:button>@endif</div>
            </form>
            <div class="crm-table-wrap mt-5"><table class="crm-table">
                <thead><tr><th>体系</th><th>等级</th><th>月门槛</th><th title="数字越小，默认显示顺序越靠前。">排序</th><th>状态</th><th></th></tr></thead>
                <tbody>@forelse ($state['grades'] as $grade)
                    @php($system = collect($state['systems'])->firstWhere('id', $grade['policy_system_id']))
                    <tr><td>{{ $system['name'] ?? '未知' }}</td><td class="font-semibold">{{ $grade['name'] }}</td><td>₩ {{ number_format($grade['monthly_threshold_krw']) }}</td><td>{{ $grade['sort_order'] }}</td><td>{{ $grade['is_active'] ? '启用' : '停用' }}</td><td><div class="flex gap-1"><flux:button wire:click="editGrade({{ $grade['id'] }})" size="sm" variant="ghost">编辑</flux:button><flux:button wire:click="toggleGrade({{ $grade['id'] }})" size="sm" variant="ghost">{{ $grade['is_active'] ? '停用' : '启用' }}</flux:button></div></td></tr>
                @empty<tr><td colspan="6" class="py-8 text-center text-zinc-500">尚未配置等级。</td></tr>@endforelse</tbody>
            </table></div>
        </section>

        <section class="grid gap-6 xl:grid-cols-2">
            <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <h3 class="font-semibold">等级机构费率</h3>
                    <flux:select wire:model.live="ruleListSort" label="查看排序" title="只改变当前费率列表的查看顺序，不改变费率优先级或生效规则。" class="min-w-44">
                        <flux:select.option value="effective_desc">生效月：新到旧</flux:select.option>
                        <flux:select.option value="effective_asc">生效月：旧到新</flux:select.option>
                        <flux:select.option value="rate_desc">费率：高到低</flux:select.option>
                        <flux:select.option value="rate_asc">费率：低到高</flux:select.option>
                        <flux:select.option value="grade_asc">政策等级：升序</flux:select.option>
                        <flux:select.option value="institution_asc">机构名称：升序</flux:select.option>
                    </flux:select>
                </div>
                <form wire:submit="saveRule" class="mt-4 space-y-3">
                    <flux:select wire:model="ruleGradeId" label="政策等级" title="该费率适用的政策等级。" required><flux:select.option value="">请选择</flux:select.option>@foreach ($state['grades'] as $grade)<flux:select.option value="{{ $grade['id'] }}">{{ $grade['name'] }}</flux:select.option>@endforeach</flux:select>
                    <flux:select wire:model="ruleInstitutionId" label="机构" title="该等级费率适用的具体机构。" required><flux:select.option value="">请选择</flux:select.option>@foreach ($state['institutions'] as $institution)<flux:select.option value="{{ $institution['id'] }}">{{ $institution['name'] }}</flux:select.option>@endforeach</flux:select>
                    <div class="grid gap-3 sm:grid-cols-2"><flux:input wire:model="ruleRateBps" type="number" min="0" max="10000" label="费率基点（100点=1%）" title="100 个基点等于 1%；例如 1200 表示 12%。"/><flux:input wire:model="ruleEffectiveMonth" type="date" label="生效月份" title="费率从所选月份第一天开始生效；已开始月份不可覆盖。" required /></div>
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
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <h3 class="font-semibold">代理商特批</h3>
                    <flux:select wire:model.live="overrideListSort" label="查看排序" title="只改变特批列表的查看顺序，不改变特批优先级或有效期。" class="min-w-44">
                        <flux:select.option value="effective_desc">生效月：新到旧</flux:select.option>
                        <flux:select.option value="effective_asc">生效月：旧到新</flux:select.option>
                        <flux:select.option value="rate_desc">费率：高到低</flux:select.option>
                        <flux:select.option value="rate_asc">费率：低到高</flux:select.option>
                        <flux:select.option value="agent_asc">代理商名称：升序</flux:select.option>
                        <flux:select.option value="institution_asc">机构范围：升序</flux:select.option>
                    </flux:select>
                </div>
                <form wire:submit="saveOverride" class="mt-4 space-y-3">
                    <flux:select wire:model="overrideAgentId" label="代理商" title="特批仅作用于所选代理商。" required><flux:select.option value="">请选择</flux:select.option>@foreach ($state['agents'] as $agent)<flux:select.option value="{{ $agent['id'] }}">{{ $agent['code'] }} · {{ $agent['name'] }}</flux:select.option>@endforeach</flux:select>
                    <flux:select wire:model="overrideInstitutionId" label="机构范围" title="留空表示覆盖该代理商的全部机构；选择机构则只覆盖该机构。"><flux:select.option value="">全部机构</flux:select.option>@foreach ($state['institutions'] as $institution)<flux:select.option value="{{ $institution['id'] }}">{{ $institution['name'] }}</flux:select.option>@endforeach</flux:select>
                    <div class="grid gap-3 sm:grid-cols-2"><flux:input wire:model="overrideRateBps" type="number" min="0" max="10000" label="费率基点（100点=1%）" title="100 个基点等于 1%；特批优先于等级机构费率。"/><flux:input wire:model="overrideEffectiveMonth" type="date" label="生效月份" title="特批从所选月份第一天开始生效；创建新版本时会截止上一版本。" required /></div>
                    <flux:textarea wire:model="overrideReason" label="特批原因" title="记录审批依据，保存后会进入审计记录。" rows="2" required />
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
