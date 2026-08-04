<div>
    <section class="crm-section-header">
        <div>
            <h2 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-50">月结中心</h2>
            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">根据已完成订单自动汇总推广费，生成月结并跟踪结算单的审核与处理进度。</p>
        </div>
        <flux:button wire:click="generate" icon="play" variant="primary">生成最新月结</flux:button>
    </section>

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

    <section class="mb-6 rounded-2xl border border-amber-200 bg-amber-50 p-5 shadow-sm dark:border-amber-900 dark:bg-amber-950/30">
        <h3 class="font-semibold text-amber-900 dark:text-amber-100">生成往期月结</h3>
        <p class="mt-1 text-sm text-amber-800 dark:text-amber-200">选择已关闭的历史周期，复用现有月结生成、审核和结清流程。已存在的周期不会重复生成。</p>
        <form wire:submit="generateHistorical" class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-end">
            <flux:select class="sm:min-w-96" wire:model="historicalPeriodEnd" label="往期节点" required>
                <flux:select.option value="">请选择周期</flux:select.option>
                @foreach ($historicalPeriods as $period)
                    <flux:select.option value="{{ $period->end->toDateString() }}">{{ $period->start->format('Y-m-d') }} 至 {{ $period->end->format('Y-m-d') }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:button class="sm:mt-6" type="submit" variant="primary">生成往期月结</flux:button>
        </form>
        @error('historicalPeriodEnd')<p class="mt-2 text-sm text-red-700 dark:text-red-300">{{ $message }}</p>@enderror
    </section>

    <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900" wire:poll.10s>
        <div class="flex items-center justify-between"><h3 class="font-semibold">月结批次</h3></div>
        <div class="crm-table-wrap mt-4"><table class="crm-table">
            <thead><tr><th>周期</th><th>进度</th><th>消费/推广费</th><th>状态</th><th></th></tr></thead>
            <tbody>
            @forelse ($runs as $run)
                @php($isCollapsed = in_array((string) $run->id, $collapsedRunIds, true))
                <tr wire:key="settlement-run-{{ $run->id }}" class="cursor-pointer" wire:click="toggleRun('{{ $run->id }}')" wire:keydown.enter="toggleRun('{{ $run->id }}')" wire:keydown.space.prevent="toggleRun('{{ $run->id }}')" tabindex="0" role="button" aria-label="{{ $isCollapsed ? '展开' : '收起' }}月结批次">
                    <td>{{ $run->period_start->format('Y-m-d') }} 至 {{ $run->period_end->format('Y-m-d') }}<div class="text-xs text-zinc-500">{{ ['manual' => '手动', 'historical' => '往期手动', 'scheduled' => '自动'][$run->trigger_source] ?? $run->trigger_source }}</div></td>
                    <td>{{ $run->processed_agents + $run->failed_agents }}/{{ $run->total_agents }}<div class="text-xs text-red-600">失败 {{ $run->failed_agents }}</div></td>
                    <td>₩ {{ number_format($run->total_consumption_krw) }}<div class="text-xs text-zinc-500">推广费 ₩ {{ number_format($run->total_commission_krw) }}</div></td>
                    <td>{{ ['queued'=>'排队中','running'=>'处理中','completed'=>'已生成','partial_failed'=>'部分失败','failed'=>'失败'][$run->status] ?? $run->status }}<div class="text-xs text-zinc-500">钉钉：{{ ['pending'=>'待下发','queued'=>'队列中','sent'=>'已发送','failed'=>'发送失败','disabled'=>'未启用'][$run->notification_status] ?? $run->notification_status }}</div></td>
                    <td class="space-x-2" x-on:keydown.stop>
                        <button type="button" class="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-sm font-semibold text-zinc-700 hover:bg-zinc-100" wire:click.stop="toggleRun('{{ $run->id }}')" aria-expanded="{{ $isCollapsed ? 'false' : 'true' }}">
                            <flux:icon :name="$isCollapsed ? 'chevron-right' : 'chevron-down'" class="size-4" aria-hidden="true" />
                            <span>{{ $isCollapsed ? '展开' : '收起' }}</span>
                        </button>
                        @if ($run->failed_agents > 0)
                            <a class="text-sm font-semibold text-red-700 hover:underline" href="{{ route('settlements.runs.failures', $run->id) }}" wire:navigate x-on:click.stop>查看 {{ $run->failed_agents }} 项失败</a>
                            <flux:button wire:click.stop="retry('{{ $run->id }}')" size="sm" variant="ghost">重试失败项</flux:button>
                        @endif
                        @if (in_array($run->notification_status, ['failed', 'disabled'], true))<flux:button wire:click.stop="retryNotification('{{ $run->id }}')" size="sm" variant="ghost">重试钉钉</flux:button>@endif
                        @if ($run->processed_agents > 0)<a class="text-sm font-semibold text-teal-700" href="{{ route('settlements.archive', $run->id) }}" x-on:click.stop>下载全部</a>@endif
                    </td>
                </tr>
                @if (! $isCollapsed)
                @foreach ($run->settlements as $settlement)
                    <tr class="bg-zinc-50/70 dark:bg-zinc-800/40">
                        <td colspan="2"><a class="font-semibold text-teal-700 hover:underline" href="{{ route('settlements.show', $settlement->id) }}" wire:navigate>{{ data_get($settlement->snapshot, 'agent.name', '代理商') }}</a></td>
                        <td>推广费 ₩ {{ number_format($settlement->total_commission_krw) }}</td>
                        <td colspan="2">{{ ['pending_review'=>'待审核','rejected'=>'已驳回','approved'=>'已通过','settled'=>'已结清'][$settlement->status] ?? $settlement->status }}</td>
                    </tr>
                @endforeach
                @endif
            @empty<tr><td colspan="5" class="py-8 text-center text-zinc-500">尚未生成月结批次。</td></tr>@endforelse
            </tbody>
        </table></div>
    </section>
</div>
