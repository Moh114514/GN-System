<div>
    <x-page-back :href="route('agents.index')" label="返回代理商管理" class="mb-4" />

    @if (session('status'))
        <div class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>
    @endif

    <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-lg font-semibold">合作档案</h2>
            <div class="flex items-center gap-2">
                <span class="crm-pill {{ $agent['cooperation_status'] === 'active' ? 'tone-green' : ($agent['cooperation_status'] === 'paused' ? 'tone-amber' : 'tone-red') }}">{{ ['active' => '合作中', 'paused' => '暂停', 'terminated' => '已终止'][$agent['cooperation_status']] }}</span>
                @if ($agent['cooperation_status'] !== 'terminated')
                    <flux:button :href="route('agents.edit', $agent['id'])" size="sm" icon="pencil-square" wire:navigate>编辑档案</flux:button>
                @endif
            </div>
        </div>
        <dl class="mt-5 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
            <div><dt class="text-xs text-zinc-500">代理商名称</dt><dd class="mt-1 font-semibold">{{ $agent['name'] }}</dd></div>
            <div><dt class="text-xs text-zinc-500">代理商编号</dt><dd class="mt-1 font-medium">{{ $agent['code'] }}</dd></div>
            <div><dt class="text-xs text-zinc-500">类型</dt><dd class="mt-1 font-medium">{{ $agent['type'] }}</dd></div>
            <div><dt class="text-xs text-zinc-500">业务角色</dt><dd class="mt-1 font-medium">{{ $agent['business_role'] ?: '—' }}</dd></div>
            <div><dt class="text-xs text-zinc-500">政策体系</dt><dd class="mt-1 font-medium">{{ $agent['policy_system'] ?? '未设置政策' }}</dd></div>
            <div><dt class="text-xs text-zinc-500">当前等级</dt><dd class="mt-1 font-medium">{{ $agent['policy_grade'] ?? '未设置等级' }}</dd></div>
            <div><dt class="text-xs text-zinc-500">联系人</dt><dd class="mt-1 font-semibold">{{ $agent['contact_name'] ?: '—' }}</dd></div>
            <div><dt class="text-xs text-zinc-500">联系方式</dt><dd class="mt-1 font-medium">{{ $agent['contact_value'] ?: '—' }}</dd></div>
            <div><dt class="text-xs text-zinc-500">合作开始</dt><dd class="mt-1 font-medium">{{ $agent['cooperation_started_on'] }}</dd></div>
            <div><dt class="text-xs text-zinc-500">合作结束</dt><dd class="mt-1 font-medium">{{ $agent['cooperation_ended_on'] ?: '—' }}</dd></div>
            <div><dt class="text-xs text-zinc-500">等级生效月</dt><dd class="mt-1 font-medium">{{ $agent['grade_effective_month'] ?: '—' }}</dd></div>
            <div><dt class="text-xs text-zinc-500">备注</dt><dd class="mt-1 font-medium">{{ $agent['notes'] ?: '—' }}</dd></div>
        </dl>
    </section>

    <div class="mt-6 grid gap-6 xl:grid-cols-2">
        <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <h3 class="text-lg font-semibold">来源客户</h3>
            <div class="crm-table-wrap mt-4"><table class="crm-table">
                <thead><tr><th>客户</th><th>建档时间</th></tr></thead>
                <tbody>
                @forelse ($agent['customers'] as $customer)
                    <tr><td><a class="font-semibold text-teal-700 hover:underline" href="{{ route('customers.show', $customer['id']) }}" wire:navigate>{{ $customer['name'] }}</a><div class="text-xs text-zinc-500">{{ $customer['code'] }}</div></td><td>{{ $customer['created_at'] }}</td></tr>
                @empty
                    <tr><td colspan="2" class="py-8 text-center text-zinc-500">暂无来源客户。</td></tr>
                @endforelse
                </tbody>
            </table></div>
        </section>
        <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <h3 class="text-lg font-semibold">关联订单</h3>
            <div class="crm-table-wrap mt-4"><table class="crm-table">
                <thead><tr><th>项目/金额</th><th>状态</th><th>推广费</th></tr></thead>
                <tbody>
                @forelse ($agent['orders'] as $order)
                    <tr>
                        <td>{{ $order->projectName }}<div class="text-xs text-zinc-500">₩ {{ number_format($order->amountKrw) }}</div></td>
                        <td>{{ $order->status === 'completed' ? '已完成' : '待完成' }}</td>
                        <td>{{ $order->commissionAmountKrw === null ? '—' : '₩ '.number_format($order->commissionAmountKrw) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="py-8 text-center text-zinc-500">暂无关联订单。</td></tr>
                @endforelse
                </tbody>
            </table></div>
        </section>
    </div>
</div>
