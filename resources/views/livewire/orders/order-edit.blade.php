<div>
    <x-page-back :href="route('orders.show', $orderId)" label="返回订单详情" class="mb-4" />
    <section class="crm-section-header">
        <div>
            <p class="text-xs font-medium text-zinc-400">订单详情 · #{{ $orderId }}</p>
            <h2 class="mt-1 text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-50">编辑订单</h2>
            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">仅可编辑待完成订单；关联客户和完成时间保持不变。订单状态请通过详情页的状态编辑入口调整。</p>
        </div>
        <flux:button href="{{ route('orders.show', $orderId) }}#status-editor" wire:navigate variant="ghost">编辑订单状态</flux:button>
    </section>

    <section class="crm-card">
        <div class="rounded-xl border border-teal-200 bg-teal-50 p-4 dark:border-teal-800 dark:bg-teal-950"><p class="text-xs text-teal-700 dark:text-teal-300">关联客户</p><p class="mt-1 font-semibold">{{ $order['customer']['name'] }} <span class="text-sm font-normal text-zinc-500">{{ $order['customer']['code'] }}</span></p></div>
        <form wire:submit="save" class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <flux:select wire:model="institutionId" label="机构" required><flux:select.option value="">请选择</flux:select.option>@foreach ($options['institutions'] as $institution)<flux:select.option value="{{ $institution['id'] }}">{{ $institution['name'] }}</flux:select.option>@endforeach</flux:select>
            <flux:select wire:model.live="channel" label="订单渠道" required><flux:select.option value="agent">代理商</flux:select.option><flux:select.option value="direct">直销</flux:select.option></flux:select>
            @if ($channel === 'agent')
                <flux:select wire:model="agentId" label="代理商" required><flux:select.option value="">请选择</flux:select.option>@foreach ($options['agents'] as $agent)<flux:select.option value="{{ $agent['id'] }}">{{ $agent['code'] }} · {{ $agent['name'] }}</flux:select.option>@endforeach</flux:select>
            @else
                <flux:select wire:model="directSalesSourceId" label="直销来源" required><flux:select.option value="">请选择</flux:select.option>@foreach ($options['direct_sources'] as $source)<flux:select.option value="{{ $source['id'] }}">{{ $source['name'] }}</flux:select.option>@endforeach</flux:select>
            @endif
            <flux:select wire:model.live="treatmentProjectId" label="施术项目字典（可选）"><flux:select.option value="">使用项目名称</flux:select.option>@foreach ($options['treatment_projects'] as $project)<flux:select.option value="{{ $project['id'] }}">{{ $project['name'] }}</flux:select.option>@endforeach</flux:select>
            <flux:input wire:model="projectName" label="项目名称" :readonly="$treatmentProjectId !== ''" required />
            <flux:input wire:model="amountKrw" type="number" min="0" step="1" label="成交金额（KRW）" required />
            <flux:input wire:model="translatorName" label="翻译姓名" />
            <flux:select wire:model="translatorLanguageId" label="翻译语种（可选）"><flux:select.option value="">未选择</flux:select.option>@foreach ($options['translator_languages'] as $language)<flux:select.option value="{{ $language['id'] }}">{{ $language['name'] }}</flux:select.option>@endforeach</flux:select>
            <div class="md:col-span-2 xl:col-span-3"><flux:textarea wire:model="notes" label="备注" rows="3" /></div>
            <div class="flex justify-end gap-2 md:col-span-2 xl:col-span-3"><flux:button href="{{ route('orders.show', $orderId) }}" wire:navigate variant="ghost">取消</flux:button><flux:button type="submit" variant="primary" wire:loading.attr="disabled">保存修改</flux:button></div>
        </form>
    </section>
</div>
