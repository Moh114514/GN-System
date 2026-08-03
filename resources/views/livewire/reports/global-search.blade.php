<div>
    <section class="crm-section-header">
        <div>
            <h2 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-50">全部搜索结果</h2>
            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">
                @if ($query === '')
                    输入关键词后，可同时搜索客户、订单项目和有权限查看的代理商。
                @else
                    关键词“{{ $query }}”的匹配结果
                @endif
            </p>
        </div>
    </section>

    @if ($query === '')
        <section class="crm-card">
            <div class="crm-panel-empty">
                <flux:icon.magnifying-glass />
                请在顶部输入搜索关键词
            </div>
        </section>
    @else
        @php
            $agentResults = $results['agents'];
            $total = $results['customers']['total']
                + $results['orders']['total']
                + ($agentResults['total'] ?? 0);
        @endphp

        <p class="mb-4 text-sm text-zinc-500">共找到 {{ $total }} 条匹配结果，按业务类型分组展示。</p>

        <div class="grid gap-4">
            <section class="crm-card">
                <header class="crm-card-header">
                    <h2>客户 <span class="text-zinc-400">({{ $results['customers']['total'] }})</span></h2>
                    <a class="crm-card-link" href="{{ route('customers.index', ['search' => $query]) }}" wire:navigate>
                        查看全部客户 <span>›</span>
                    </a>
                </header>
                <div class="crm-table-wrap">
                    <table class="crm-table">
                        <thead><tr><th>客户</th><th>当前状态</th><th></th></tr></thead>
                        <tbody>
                            @forelse ($results['customers']['items'] as $customer)
                                <tr>
                                    <td>
                                        <a class="font-semibold text-teal-700 hover:underline" href="{{ route('customers.show', $customer['id']) }}" wire:navigate>{{ $customer['name'] }}</a>
                                        <div class="text-xs text-zinc-500">{{ $customer['code'] }}</div>
                                    </td>
                                    <td>{{ $customer['status'] }}</td>
                                    <td class="text-right"><a class="crm-card-link" href="{{ route('customers.show', $customer['id']) }}" wire:navigate>查看档案 <span>›</span></a></td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="crm-table-empty">没有匹配的客户。</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="crm-card">
                <header class="crm-card-header">
                    <h2>订单项目 <span class="text-zinc-400">({{ $results['orders']['total'] }})</span></h2>
                    <a class="crm-card-link" href="{{ route('reports.search', ['projectName' => $query]) }}" wire:navigate>
                        查看全部订单项目 <span>›</span>
                    </a>
                </header>
                <div class="crm-table-wrap">
                    <table class="crm-table">
                        <thead><tr><th>施术项目</th><th>客户</th><th>代理商</th><th>成交金额</th><th>成交时间</th></tr></thead>
                        <tbody>
                            @forelse ($results['orders']['items'] as $order)
                                <tr>
                                    <td>
                                        <a class="font-semibold text-teal-700 hover:underline" href="{{ route('customers.orders', $order['customer_id']) }}" wire:navigate>{{ $order['project'] }}</a>
                                    </td>
                                    <td><a class="hover:underline" href="{{ route('customers.show', $order['customer_id']) }}" wire:navigate>{{ $order['customer'] }}</a></td>
                                    <td>{{ $order['agent'] }}</td>
                                    <td>₩{{ number_format((int) $order['amount_krw']) }}</td>
                                    <td>{{ $order['completed_at'] }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="crm-table-empty">没有匹配的订单项目。</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            @if ($agentResults !== null)
                <section class="crm-card">
                    <header class="crm-card-header">
                        <h2>代理商 <span class="text-zinc-400">({{ $agentResults['total'] }})</span></h2>
                        <a class="crm-card-link" href="{{ route('agents.index', ['search' => $query]) }}" wire:navigate>
                            查看全部代理商 <span>›</span>
                        </a>
                    </header>
                    <div class="crm-table-wrap">
                        <table class="crm-table">
                            <thead><tr><th>代理商</th><th>合作状态</th><th></th></tr></thead>
                            <tbody>
                                @forelse ($agentResults['items'] as $agent)
                                    <tr>
                                        <td>
                                            <a class="font-semibold text-teal-700 hover:underline" href="{{ route('agents.show', $agent['id']) }}" wire:navigate>{{ $agent['name'] }}</a>
                                            <div class="text-xs text-zinc-500">{{ $agent['code'] }}</div>
                                        </td>
                                        <td>{{ ['active' => '合作中', 'paused' => '暂停', 'terminated' => '已终止'][$agent['status']] ?? $agent['status'] }}</td>
                                        <td class="text-right"><a class="crm-card-link" href="{{ route('agents.show', $agent['id']) }}" wire:navigate>查看档案 <span>›</span></a></td>
                                    </tr>
                                @empty
                                    <tr><td colspan="3" class="crm-table-empty">没有匹配的代理商。</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </section>
            @endif

            @if ($total === 0)
                <p class="py-2 text-center text-sm text-zinc-500">没有找到与“{{ $query }}”匹配的结果。</p>
            @endif
        </div>
    @endif
</div>
