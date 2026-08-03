<div>
    <x-page-back :href="route('orders.index')" label="返回订单管理" class="mb-4" />

    <section class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h2 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-50">订单回收站</h2>
            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">查看已取消并移入回收站的订单；恢复后仍需重新打开才能继续使用。</p>
        </div>
    </section>

    <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        @php
            $selectedInstitution = collect($options['institutions'])->firstWhere('id', (int) $institutionFilter);
            $selectedAgent = collect($options['agents'])->firstWhere('id', (int) $agentFilter);
            $hasFilters = $search !== '' || $statusFilter !== '' || $channelFilter !== '' || $institutionFilter !== '' || $agentFilter !== '' || $perPage !== 20;
        @endphp
        <div class="flex flex-wrap items-center gap-2">
            <flux:input class="mr-1 w-full sm:w-72" wire:model.live.debounce.350ms="search" icon="magnifying-glass" placeholder="搜索订单号、客户或项目" size="sm" />
            <flux:select class="w-32" wire:model.live="statusFilter" size="sm" aria-label="订单状态筛选">
                <flux:select.option value="">全部状态</flux:select.option>
                <flux:select.option value="cancelled">已取消</flux:select.option>
            </flux:select>
            <flux:select class="w-32" wire:model.live="channelFilter" size="sm" aria-label="订单渠道筛选">
                <flux:select.option value="">全部渠道</flux:select.option>
                <flux:select.option value="agent">代理商</flux:select.option>
                <flux:select.option value="direct">直销</flux:select.option>
            </flux:select>
            <flux:dropdown>
                <flux:button class="rounded-full bg-zinc-100 dark:bg-zinc-800" variant="ghost" size="sm" icon:trailing="chevron-down">{{ $selectedInstitution['name'] ?? '全部机构' }}</flux:button>
                <flux:menu class="max-h-72 overflow-y-auto">
                    <flux:menu.item wire:click="$set('institutionFilter', '')">全部机构</flux:menu.item>
                    @foreach ($options['institutions'] as $institution)
                        <flux:menu.item wire:click="$set('institutionFilter', '{{ $institution['id'] }}')">{{ $institution['name'] }}</flux:menu.item>
                    @endforeach
                </flux:menu>
            </flux:dropdown>
            <flux:dropdown>
                <flux:button class="rounded-full bg-zinc-100 dark:bg-zinc-800" variant="ghost" size="sm" icon:trailing="chevron-down">{{ $selectedAgent['name'] ?? '全部代理商' }}</flux:button>
                <flux:menu class="max-h-72 overflow-y-auto">
                    <flux:menu.item wire:click="$set('agentFilter', '')">全部代理商</flux:menu.item>
                    @foreach ($options['agents'] as $agent)
                        <flux:menu.item wire:click="$set('agentFilter', '{{ $agent['id'] }}')">{{ $agent['name'] }}</flux:menu.item>
                    @endforeach
                </flux:menu>
            </flux:dropdown>
            <flux:dropdown>
                <flux:button class="rounded-full bg-zinc-100 dark:bg-zinc-800" variant="ghost" size="sm" icon:trailing="chevron-down">{{ $perPage }} 条/页</flux:button>
                <flux:menu>
                    @foreach ([20, 50, 100] as $size)
                        <flux:menu.item wire:click="$set('perPage', {{ $size }})">{{ $size }} 条/页</flux:menu.item>
                    @endforeach
                </flux:menu>
            </flux:dropdown>
            @if ($hasFilters)
                <flux:button wire:click="clearFilters" variant="ghost" size="sm" icon="x-mark">清除</flux:button>
            @endif
        </div>

        <div class="crm-table-wrap mt-5">
            <table class="crm-table">
                <thead><tr><th>订单</th><th>客户</th><th>机构 / 渠道</th><th>成交金额</th><th>状态</th><th>时间</th><th></th></tr></thead>
                <tbody>
                    @forelse ($orders as $order)
                        <tr wire:key="recycle-order-{{ $order['id'] }}">
                            <td><a class="font-semibold text-teal-700 hover:underline" href="{{ route('orders.show', $order['id']) }}" wire:navigate>{{ $order['project_name'] }}</a><div class="text-xs text-zinc-500">#{{ $order['id'] }}</div></td>
                            <td><a class="font-semibold text-teal-700 hover:underline" href="{{ route('customers.show', $order['customer_id']) }}" wire:navigate>{{ $order['customer_name'] }}</a><div class="text-xs text-zinc-500">{{ $order['customer_code'] }}</div></td>
                            <td>{{ $order['institution'] }}<div class="text-xs text-zinc-500">{{ $order['channel'] === 'agent' ? '代理商' : '直销' }} · {{ $order['source'] }}</div></td>
                            <td>₩ {{ number_format($order['amount_krw']) }}</td>
                            <td><span class="crm-pill tone-red">已取消</span></td>
                            <td>{{ $order['completed_at'] ?? $order['created_at'] }}<div class="text-xs text-zinc-500">{{ $order['completed_at'] ? '成交时间' : '创建时间' }}</div></td>
                            <td><flux:button href="{{ route('orders.show', $order['id']) }}" wire:navigate variant="ghost" size="sm">查看详情</flux:button></td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-10 text-center text-zinc-500">回收站暂无订单。</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-5">{{ $orders->links() }}</div>
    </section>
</div>
