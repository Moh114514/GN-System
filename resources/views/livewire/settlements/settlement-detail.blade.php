<div>
    <x-page-back :href="route('settlements.index')" label="返回月结中心" class="mb-4" />

    @php($canRegenerateGeneration = in_array($settlement->generation_status, ['pending', 'unverified'], true))
    @php($needsRegeneration = in_array($settlement->status, ['pending_review', 'rejected']) && $canRegenerateGeneration && $settlement->settlement_run_id !== null)
    @php($generationUnverified = $settlement->generation_status === 'unverified')
    @php($generationNotApplicable = $settlement->generation_status === 'not_applicable')
    @php($generationRecoveryRequired = $generationUnverified && $settlement->settlement_run_id === null)
    @if ($needsRegeneration)
        <section id="{{ $needsRegeneration ? 'settlement-generation-alert' : 'settlement-generation-regeneration-note' }}" data-business-alert data-business-alert-key="{{ $settlement->id }}-{{ $settlement->generation_status }}-regeneration" tabindex="-1" class="scroll-mt-20 mb-5 rounded-xl border border-amber-300 bg-amber-50 px-5 py-4 text-amber-900 dark:border-amber-700 dark:bg-amber-950/30 dark:text-amber-100">
            <h3 class="font-semibold">月结明细尚未生成</h3>
            <p class="mt-1 text-sm">当前消费合计和推广费合计暂时显示为 ₩0。请先重新生成月结明细，核对金额和结算汇率后再提交审核。</p>
            <flux:button class="mt-3" wire:click="regenerateSettlement" wire:loading.attr="disabled" wire:target="regenerateSettlement" variant="primary">重新生成月结明细</flux:button>
        </section>
    @endif
    @if ($generationUnverified)
        <section id="{{ $needsRegeneration ? 'settlement-generation-unverified-note' : 'settlement-generation-alert' }}" data-business-alert data-business-alert-key="{{ $settlement->id }}-{{ $settlement->generation_status }}-unverified" tabindex="-1" class="scroll-mt-20 mb-5 rounded-xl border border-red-300 bg-red-50 px-5 py-4 text-red-900 dark:border-red-700 dark:bg-red-950/30 dark:text-red-100">
            <h3 class="font-semibold">历史月结生成状态无法确认</h3>
            <p class="mt-1 text-sm">系统没有找到足够的生成批次、明细或结算文档证据，已禁止直接审核。请由超级管理员填写核验依据，选择按历史导入归档，或创建恢复批次重新生成。</p>
            @if ($generationRecoveryRequired && auth()->user()?->is_super_admin)
                <form wire:submit="createRecoveryBatch" class="mt-4 space-y-3">
                    <flux:textarea wire:model="generationRecoveryBasis" label="核验依据" rows="3" required />
                    <div class="flex flex-wrap gap-2">
                        <flux:button type="submit" wire:loading.attr="disabled" wire:target="createRecoveryBatch" variant="primary">创建恢复批次并重新生成</flux:button>
                        <flux:button type="button" wire:click="recoverUnverifiedAsHistorical" wire:loading.attr="disabled" wire:target="recoverUnverifiedAsHistorical" variant="ghost">核验为历史导入</flux:button>
                    </div>
                </form>
            @elseif ($settlement->settlement_run_id !== null)
                <p class="mt-2 text-sm">已有恢复批次，请先重新生成月结明细后再审核。</p>
            @endif
        </section>
    @endif
    @if ($generationNotApplicable)
        <section class="mb-5 rounded-xl border border-zinc-300 bg-zinc-50 px-5 py-4 text-zinc-800 dark:border-zinc-700 dark:bg-zinc-950/30 dark:text-zinc-100">
            <h3 class="font-semibold">历史月结，仅供查看</h3>
            <p class="mt-1 text-sm">该记录已核验为历史导入或不参与新月结生成，不允许重新生成或进入当前审核流程。</p>
        </section>
    @endif

    <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div><p class="text-xs font-medium text-zinc-400">月结详情</p><h2 class="mt-1 text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-50">{{ data_get($settlement->snapshot, 'agent.name', '代理商') }}</h2><p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ $settlement->period_start->format('Y-m-d') }} 至 {{ $settlement->period_end->format('Y-m-d') }}</p></div>
            <div class="flex flex-wrap items-center justify-end gap-3">
                <div class="flex items-center gap-2" aria-label="同批次月结导航">
                    @if ($previousSettlement)
                        <a class="text-sm font-semibold text-teal-700 hover:underline" href="{{ route('settlements.show', $previousSettlement->id) }}" wire:navigate>← 上一条</a>
                    @else
                        <span class="text-sm text-zinc-400">← 上一条</span>
                    @endif
                    @if ($nextSettlement)
                        <a class="text-sm font-semibold text-teal-700 hover:underline" href="{{ route('settlements.show', $nextSettlement->id) }}" wire:navigate>下一条 →</a>
                    @else
                        <span class="text-sm text-zinc-400">下一条 →</span>
                    @endif
                </div>
                <span class="crm-pill tone-blue">{{ ['pending_review'=>'待审核','rejected'=>'已驳回','approved'=>'已通过','settled'=>'已结清','paid'=>'历史已结清','reconciled'=>'历史已对账'][$settlement->status] ?? $settlement->status }}</span>
            </div>
        </div>
        <dl class="mt-5 grid gap-4 sm:grid-cols-3"><div><dt class="text-xs text-zinc-500">消费合计</dt><dd class="font-semibold">₩ {{ number_format($settlement->total_consumption_krw) }}</dd></div><div><dt class="text-xs text-zinc-500">推广费合计</dt><dd class="font-semibold">₩ {{ number_format($settlement->total_commission_krw) }}</dd></div><div><dt class="text-xs text-zinc-500">人民币应付</dt><dd class="font-semibold">{{ $settlement->exchange_rate_krw_per_cny ? '¥ '.number_format($settlement->payout_amount_cny_fen / 100, 2) : '待审核' }}</dd></div></dl>
    </section>

    @if (in_array($settlement->status, ['pending_review', 'rejected']) && ! $generationNotApplicable && ! $generationRecoveryRequired)
        <section class="mt-6 grid gap-5 lg:grid-cols-2">
            <form wire:submit="approve" class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                <h3 class="font-semibold">审核通过</h3>
                @if ($settlement->exchange_rate_quote_error)
                    <p class="mt-2 text-sm text-amber-700 dark:text-amber-300">最新报价不可用，已保留当前汇率，请人工核对或修改。{{ $settlement->exchange_rate_quote_error }}</p>
                @elseif ($settlement->exchange_rate_quote_status === 'available')
                    <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">已自动填入接口盒子报价（每日更新，报价时间：{{ $settlement->exchange_rate_quoted_at?->format('Y-m-d H:i') }}），可按实际结算值直接修改。</p>
                @else
                    <p class="mt-2 text-sm text-amber-700 dark:text-amber-300">暂无可用报价，请人工填写结算汇率。</p>
                @endif
                <div class="mt-3 flex items-end gap-3">
                    <flux:input wire:model="exchangeRate" class="flex-1" type="number" step="0.000001" min="0.000001" label="KRW/CNY 结算汇率" required />
                    <flux:button type="button" wire:click="refreshExchangeRateQuote" wire:loading.attr="disabled" wire:target="refreshExchangeRateQuote" variant="ghost">获取最新报价</flux:button>
                </div>
                <flux:button class="mt-3" type="submit" variant="primary" :disabled="$needsRegeneration || $generationUnverified || $generationNotApplicable">通过并生成 Word/PDF</flux:button>
            </form>
            <form wire:submit="reject" class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900"><h3 class="font-semibold">驳回</h3><flux:textarea wire:model="rejectionReason" class="mt-3" label="问题与退回原因" rows="2" required /><flux:button class="mt-3" type="submit" variant="danger">驳回月结</flux:button></form>
        </section>
    @elseif ($settlement->status === 'approved')
        <div class="mt-6"><flux:button wire:click="settle" variant="primary">确认信息并结算</flux:button></div>
    @endif

    @if (in_array($settlement->status, ['approved', 'settled']))
        <section class="mt-6 rounded-2xl border border-amber-200 bg-amber-50 p-5 dark:border-amber-900 dark:bg-amber-950/30">
            <h3 class="font-semibold">受控状态更正</h3><p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">仅超级管理员可在“待审核”“已审核”和“已结清”之间更正；历史已结清、已对账记录不在此范围内。回退到待审核会撤销结算明细、失效文档和汇总，并写入审计；请按页面提示重新生成明细后再审核。</p>
            <form wire:submit="correctStatus" class="mt-3 grid gap-3 sm:grid-cols-2"><flux:select wire:model="correctionTarget" label="目标状态" required><flux:select.option value="pending_review">待审核</flux:select.option><flux:select.option value="approved">已审核</flux:select.option><flux:select.option value="settled">已结清</flux:select.option></flux:select><flux:textarea wire:model="correctionReason" label="更正原因" rows="2" required /><div class="sm:col-span-2"><flux:button type="submit" variant="danger">提交状态更正</flux:button></div></form>
        </section>
    @endif

    <section class="mt-6 rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex items-center justify-between"><h3 class="font-semibold">结算明细</h3><div class="flex gap-2">@if ($needsRegeneration)<flux:button wire:click="regenerateSettlement" size="sm" variant="primary">重新生成月结明细</flux:button>@elseif (in_array($settlement->status, ['approved', 'settled']))<flux:button wire:click="regenerateDocuments" size="sm" variant="ghost">重新生成</flux:button>@endif @foreach ($documents as $document)<a class="text-sm font-semibold text-teal-700" href="{{ route('settlements.documents.download', $document->id) }}">下载 {{ strtoupper($document->format) }}</a>@endforeach</div></div>
        @if ($settlement->generation_status === 'generated' && $items->isEmpty())
            <p class="mt-3 text-sm text-zinc-500">本周期没有已完成订单，已正常生成零订单月结。</p>
        @endif
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
