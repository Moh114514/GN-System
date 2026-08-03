<div>
    <x-page-back :href="route('settlements.index')" label="返回月结中心" class="mb-4" />
    @if (session('status'))<div class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>@endif
    @error('workflow')<div class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $message }}</div>@enderror

    <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div><p class="text-xs font-medium text-zinc-400">月结详情</p><h2 class="mt-1 text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-50">{{ data_get($settlement->snapshot, 'agent.name', '代理商') }}</h2><p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ $settlement->period_start->format('Y-m-d') }} 至 {{ $settlement->period_end->format('Y-m-d') }}</p></div>
            <span class="crm-pill tone-blue">{{ ['pending_review'=>'待审核','rejected'=>'已驳回','approved'=>'已通过','settled'=>'已结清','paid'=>'历史已结清','reconciled'=>'历史已对账'][$settlement->status] ?? $settlement->status }}</span>
        </div>
        <dl class="mt-5 grid gap-4 sm:grid-cols-3"><div><dt class="text-xs text-zinc-500">消费合计</dt><dd class="font-semibold">₩ {{ number_format($settlement->total_consumption_krw) }}</dd></div><div><dt class="text-xs text-zinc-500">推广费合计</dt><dd class="font-semibold">₩ {{ number_format($settlement->total_commission_krw) }}</dd></div><div><dt class="text-xs text-zinc-500">人民币应付</dt><dd class="font-semibold">{{ $settlement->exchange_rate_krw_per_cny ? '¥ '.number_format($settlement->payout_amount_cny_fen / 100, 2) : '待审核' }}</dd></div></dl>
    </section>

    @if (in_array($settlement->status, ['pending_review', 'rejected']))
        <section class="mt-6 grid gap-5 lg:grid-cols-2">
            <form wire:submit="approve" class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900"><h3 class="font-semibold">审核通过</h3><flux:input wire:model="exchangeRate" class="mt-3" type="number" step="0.000001" min="0.000001" label="KRW/CNY 结算汇率" required /><flux:button class="mt-3" type="submit" variant="primary">通过并生成 Word/PDF</flux:button></form>
            <form wire:submit="reject" class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900"><h3 class="font-semibold">驳回</h3><flux:textarea wire:model="rejectionReason" class="mt-3" label="问题与退回原因" rows="2" required /><flux:button class="mt-3" type="submit" variant="danger">驳回月结</flux:button></form>
        </section>
    @elseif ($settlement->status === 'approved')
        <div class="mt-6"><flux:button wire:click="settle" variant="primary">确认外部结算并归档</flux:button></div>
    @endif

    <section class="mt-6 rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex items-center justify-between"><h3 class="font-semibold">结算明细</h3><div class="flex gap-2">@if (in_array($settlement->status, ['approved', 'settled']))<flux:button wire:click="regenerateDocuments" size="sm" variant="ghost">重新生成</flux:button>@endif @foreach ($documents as $document)<a class="text-sm font-semibold text-teal-700" href="{{ route('settlements.documents.download', $document->id) }}">下载 {{ strtoupper($document->format) }}</a>@endforeach</div></div>
        <div class="crm-table-wrap mt-4"><table class="crm-table"><thead><tr><th>订单</th><th>项目/完成日期</th><th>消费额</th><th>费率</th><th>推广费</th></tr></thead><tbody>
            @forelse ($items as $item) @php($snapshot = is_string($item->rule_snapshot) ? json_decode($item->rule_snapshot, true) : (array) $item->rule_snapshot)
                <tr><td>#{{ data_get($snapshot, 'order.id') }}</td><td>{{ data_get($snapshot, 'order.project_name') }}<div class="text-xs text-zinc-500">{{ data_get($snapshot, 'order.completed_on') }}</div></td><td>₩ {{ number_format($item->consumption_krw) }}</td><td>{{ number_format(data_get($snapshot, 'rate_bps', 0) / 100, 2) }}%</td><td>₩ {{ number_format($item->commission_krw) }}</td></tr>
            @empty<tr><td colspan="5" class="py-8 text-center text-zinc-500">本周期没有结算明细。</td></tr>@endforelse
        </tbody></table></div>
    </section>

    @if ($suggestion)
        <section class="mt-6 rounded-2xl border border-amber-200 bg-amber-50 p-5 dark:border-amber-900 dark:bg-amber-950/30">
            <h3 class="font-semibold">等级调整建议</h3><p class="mt-2 text-sm">本月推广费 ₩ {{ number_format($suggestion->monthly_commission_krw) }}，建议从等级 #{{ $suggestion->current_grade_id }} 调整为 #{{ $suggestion->recommended_grade_id }}。新等级仅从下月生效。</p>
            @if ($suggestion->status === 'pending')<flux:input wire:model="suggestionReason" class="mt-3" label="复核说明" /><div class="mt-3 flex gap-2"><flux:button wire:click="reviewSuggestion({{ $suggestion->id }}, true)">批准建议</flux:button><flux:button wire:click="reviewSuggestion({{ $suggestion->id }}, false)" variant="ghost">维持原等级</flux:button></div>@else<p class="mt-2 text-sm">处理结果：{{ $suggestion->status }}</p>@endif
        </section>
    @endif
</div>
