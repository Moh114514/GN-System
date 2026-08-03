<div>
    <x-page-back :href="route('customers.show', $customerId)" label="返回客户详情" class="mb-4" />
    <section class="mb-6">
        <p class="text-xs font-medium text-zinc-400">客户订单 · {{ $context['customer']['code'] }}</p>
        <h2 class="mt-1 text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-50"><span class="font-semibold">{{ $context['customer']['name'] }}</span>的订单</h2>
        <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">录入待完成或已完成订单；代理商订单完成时会自动核算并锁定推广费。</p>
    </section>

    @if (session('status'))
        <div class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>
    @endif
    @error('order') <div class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $message }}</div> @enderror
    @error('completion') <div class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $message }}</div> @enderror

    <div class="grid gap-6 xl:grid-cols-[24rem_minmax(0,1fr)]">
        <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <h3 class="font-semibold">登记订单</h3>
            <form wire:submit="save" class="mt-4 space-y-4">
                <flux:select wire:model="institutionId" label="机构" required>
                    <flux:select.option value="">请选择</flux:select.option>
                    @foreach ($context['institutions'] as $institution)
                        <flux:select.option value="{{ $institution['id'] }}">{{ $institution['name'] }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model.live="channel" label="订单渠道" required>
                    <flux:select.option value="agent">代理商</flux:select.option>
                    <flux:select.option value="direct">直销</flux:select.option>
                </flux:select>
                @if ($channel === 'agent')
                    <flux:select wire:model="agentId" label="代理商" required>
                        <flux:select.option value="">请选择</flux:select.option>
                        @foreach ($context['agents'] as $agent)
                            <flux:select.option value="{{ $agent['id'] }}">{{ $agent['code'] }} · {{ $agent['name'] }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @else
                    <flux:select wire:model="directSalesSourceId" label="直销来源" required>
                        <flux:select.option value="">请选择</flux:select.option>
                        @foreach ($context['direct_sources'] as $source)
                            <flux:select.option value="{{ $source['id'] }}">{{ $source['name'] }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif
                <flux:select wire:model="treatmentProjectId" label="施术项目字典（可选）">
                    <flux:select.option value="">手工填写 / 未归类</flux:select.option>
                    @foreach ($context['treatment_projects'] as $project)
                        <flux:select.option value="{{ $project['id'] }}">{{ $project['name'] }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="projectName" label="项目名称快照" :required="$treatmentProjectId === ''" />
                <flux:input wire:model="amountKrw" type="number" min="0" step="1" label="成交金额（KRW）" required />
                <flux:select wire:model.live="status" label="订单状态" required>
                    <flux:select.option value="pending">待完成</flux:select.option>
                    <flux:select.option value="completed">已完成</flux:select.option>
                </flux:select>
                @if ($status === 'completed')
                    <flux:input wire:model="completedOn" type="datetime-local" label="成交时间" required />
                @endif
                <flux:select wire:model="translatorLanguageId" label="翻译语种（可选）">
                    <flux:select.option value="">未选择</flux:select.option>
                    @foreach ($context['translator_languages'] as $language)
                        <flux:select.option value="{{ $language['id'] }}">{{ $language['name'] }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="translatorName" label="翻译姓名" />
                <flux:textarea wire:model="notes" label="备注" rows="3" />
                <flux:button type="submit" variant="primary" class="w-full">保存订单</flux:button>
            </form>
        </section>

        <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div>
                <div><h3 class="font-semibold">订单记录</h3><p class="mt-1 text-sm text-zinc-500">已完成订单和推广费快照均不可由此页面修改。</p></div>
            </div>
            <div class="crm-table-wrap mt-4"><table class="crm-table">
                <thead><tr><th>订单</th><th>渠道</th><th>状态</th><th>推广费</th><th></th></tr></thead>
                <tbody>
                @forelse ($context['orders'] as $order)
                    <tr wire:key="order-{{ $order['id'] }}">
                        <td>{{ $order['projectName'] }}<div class="text-xs text-zinc-500">₩ {{ number_format($order['amountKrw']) }}</div></td>
                        <td>{{ $order['channel'] === 'agent' ? '代理商' : '直销' }}</td>
                        <td>{{ $order['status'] === 'completed' ? '已完成' : '待完成' }}</td>
                        <td>{{ $order['commissionAmountKrw'] === null ? '—' : '₩ '.number_format($order['commissionAmountKrw']).' · '.number_format($order['commissionRateBps'] / 100, 2).'%' }}</td>
                        <td>@if ($order['status'] === 'pending')<flux:button wire:click="complete({{ $order['id'] }})" size="sm">标记完成</flux:button>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-10 text-center text-zinc-500">尚未登记订单。</td></tr>
                @endforelse
                </tbody>
            </table></div>
        </section>
    </div>
</div>
