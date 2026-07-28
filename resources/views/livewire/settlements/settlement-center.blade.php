<div>
    <section class="crm-section-header">
        <div>
            <p class="crm-eyebrow">财务管理</p>
            <h2>月结中心</h2>
            <p>按已完成订单的不可变推广费快照生成月结、审核结算单并跟踪处理进度。</p>
        </div>
        <flux:button wire:click="generate" icon="play" variant="primary">生成最近已结束周期</flux:button>
    </section>

    @if (session('status'))<div class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>@endif
    @error('configuration')<div class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $message }}</div>@enderror

    <section class="mb-6 rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <h3 class="font-semibold">结算周期配置</h3>
        <p class="mt-1 text-sm text-zinc-500">边界日当天关闭上一周期；修改只从下一个边界开始生效。</p>
        <form wire:submit="saveConfiguration" class="mt-4 grid items-end gap-3 sm:grid-cols-4">
            <flux:input wire:model="boundaryDay" type="number" min="1" max="28" label="每月边界日" required />
            <flux:input wire:model="triggerTime" type="time" label="触发时间" required />
            <flux:checkbox wire:model="confirmConfigurationChange" label="确认未完成周期继续使用旧配置" />
            <flux:button type="submit">保存下一周期配置</flux:button>
        </form>
    </section>

    <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex items-center justify-between"><h3 class="font-semibold">月结批次</h3></div>
        <div class="crm-table-wrap mt-4"><table class="crm-table">
            <thead><tr><th>周期</th><th>进度</th><th>消费/推广费</th><th>状态</th><th></th></tr></thead>
            <tbody>
            @forelse ($runs as $run)
                <tr wire:poll.10s>
                    <td>{{ $run->period_start->format('Y-m-d') }} 至 {{ $run->period_end->format('Y-m-d') }}<div class="text-xs text-zinc-500">{{ $run->trigger_source === 'manual' ? '手动' : '自动' }}</div></td>
                    <td>{{ $run->processed_agents + $run->failed_agents }}/{{ $run->total_agents }}<div class="text-xs text-red-600">失败 {{ $run->failed_agents }}</div></td>
                    <td>₩ {{ number_format($run->total_consumption_krw) }}<div class="text-xs text-zinc-500">推广费 ₩ {{ number_format($run->total_commission_krw) }}</div></td>
                    <td>{{ ['queued'=>'排队中','running'=>'处理中','completed'=>'已生成','partial_failed'=>'部分失败','failed'=>'失败'][$run->status] ?? $run->status }}<div class="text-xs text-zinc-500">钉钉：{{ ['pending'=>'待下发','queued'=>'队列中','sent'=>'已发送','failed'=>'发送失败','disabled'=>'未启用'][$run->notification_status] ?? $run->notification_status }}</div></td>
                    <td class="space-x-2">
                        @if ($run->failed_agents > 0)<flux:button wire:click="retry('{{ $run->id }}')" size="sm" variant="ghost">重试失败项</flux:button>@endif
                        @if (in_array($run->notification_status, ['failed', 'disabled'], true))<flux:button wire:click="retryNotification('{{ $run->id }}')" size="sm" variant="ghost">重试钉钉</flux:button>@endif
                        @if ($run->processed_agents > 0)<a class="text-sm font-semibold text-teal-700" href="{{ route('settlements.archive', $run->id) }}">下载全部</a>@endif
                    </td>
                </tr>
                @foreach (\App\Modules\Settlement\Infrastructure\Models\Settlement::query()->where('settlement_run_id', $run->id)->get() as $settlement)
                    <tr class="bg-zinc-50/70 dark:bg-zinc-800/40">
                        <td colspan="2"><a class="font-semibold text-teal-700 hover:underline" href="{{ route('settlements.show', $settlement->id) }}" wire:navigate>{{ data_get($settlement->snapshot, 'agent.name', '代理商') }}</a></td>
                        <td>推广费 ₩ {{ number_format($settlement->total_commission_krw) }}</td>
                        <td colspan="2">{{ ['pending_review'=>'待审核','rejected'=>'已驳回','approved'=>'已通过','settled'=>'已结清'][$settlement->status] ?? $settlement->status }}</td>
                    </tr>
                @endforeach
            @empty<tr><td colspan="5" class="py-8 text-center text-zinc-500">尚未生成月结批次。</td></tr>@endforelse
            </tbody>
        </table></div>
    </section>
</div>
