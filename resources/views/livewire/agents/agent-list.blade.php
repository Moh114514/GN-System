<div>
    <section class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h2 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-50">代理商管理</h2>
            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">统一维护合作档案、政策等级与订单推广费依据。</p>
        </div>
        <div class="flex shrink-0 gap-2">
            <flux:button :href="route('agent-configuration.index')" variant="ghost" size="sm" icon="cog-6-tooth" wire:navigate>代理商配置</flux:button>
            <flux:button :href="route('agents.create')" variant="primary" size="sm" icon="plus" wire:navigate>新建代理商</flux:button>
        </div>
    </section>

    @if (session('status'))
        <div class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>
    @endif

    <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex flex-wrap items-center gap-2">
            <flux:input class="w-full sm:w-72" wire:model.live.debounce.350ms="search" icon="magnifying-glass" placeholder="搜索名称或编号" size="sm" />
            <flux:select wire:model.live="status" class="w-40" size="sm">
                <flux:select.option value="">全部状态</flux:select.option>
                <flux:select.option value="active">合作中</flux:select.option>
                <flux:select.option value="paused">暂停</flux:select.option>
                <flux:select.option value="terminated">已终止</flux:select.option>
            </flux:select>
            <flux:select wire:model.live="typeCodeId" class="w-44" size="sm">
                <flux:select.option value="">全部类型</flux:select.option>
                @foreach ($filterOptions['types'] as $type)
                    <flux:select.option value="{{ $type['id'] }}">{{ $type['code'] }} · {{ $type['name'] }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:select wire:model.live="policySystemId" class="w-48" size="sm">
                <flux:select.option value="">全部政策体系</flux:select.option>
                @foreach ($filterOptions['systems'] as $system)
                    <flux:select.option value="{{ $system['id'] }}">{{ $system['name'] }}</flux:select.option>
                @endforeach
            </flux:select>
            @php
                $visibleGrades = collect($filterOptions['grades'])
                    ->when($policySystemId !== '', fn ($grades) => $grades->where('policy_system_id', (int) $policySystemId));
            @endphp
            <flux:select wire:model.live="policyGradeId" class="w-44" size="sm">
                <flux:select.option value="">全部等级</flux:select.option>
                @foreach ($visibleGrades as $grade)
                    <flux:select.option value="{{ $grade['id'] }}">{{ $grade['name'] }}</flux:select.option>
                @endforeach
            </flux:select>
            @if ($search !== '' || $status !== '' || $typeCodeId !== '' || $policySystemId !== '' || $policyGradeId !== '')
                <flux:button wire:click="clearFilters" variant="ghost" size="sm" icon="x-mark">清除</flux:button>
            @endif
        </div>

        <div class="crm-table-wrap mt-5">
            <table class="crm-table">
                <thead><tr><th>代理商</th><th>类型</th><th>政策体系</th><th>当前等级</th><th>合作状态</th><th>建档时间</th></tr></thead>
                <tbody>
                    @forelse ($agents as $agent)
                        <tr wire:key="agent-{{ $agent['id'] }}">
                            <td>
                                <a class="font-semibold text-teal-700 hover:underline" href="{{ route('agents.show', $agent['id']) }}" wire:navigate>{{ $agent['name'] }}</a>
                                <div class="text-xs text-zinc-500">{{ $agent['code'] }}</div>
                            </td>
                            <td>{{ $agent['type'] }}</td>
                            <td>{{ $agent['policy'] }}</td>
                            <td>{{ $agent['grade'] }}</td>
                            <td><span class="crm-pill {{ $agent['status'] === 'active' ? 'tone-green' : ($agent['status'] === 'paused' ? 'tone-amber' : 'tone-red') }}">{{ ['active' => '合作中', 'paused' => '暂停', 'terminated' => '已终止'][$agent['status']] }}</span></td>
                            <td>{{ $agent['created_at'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-10 text-center text-zinc-500">没有符合条件的代理商。</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-5">{{ $agents->links() }}</div>
    </section>
</div>
