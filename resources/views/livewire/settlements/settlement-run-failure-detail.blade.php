<div>
    <x-page-back :href="route('settlements.index')" label="返回月结中心" class="mb-4" />

    <section class="crm-section-header">
        <div>
            <p class="text-xs font-medium text-zinc-400">月结失败详情</p>
            <h2 class="mt-1 text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-50">批次 {{ $run->id }}</h2>
            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ $run->period_start->format('Y-m-d') }} 至 {{ $run->period_end->format('Y-m-d') }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a class="rounded-lg px-3 py-2 text-sm font-semibold text-teal-700 hover:bg-teal-50" href="{{ route('settlements.runs.failures.download', $run->id) }}">下载失败报告</a>
            @if ($failures !== [])
                <flux:button wire:click="retryAll" wire:loading.attr="disabled" wire:target="retryAll" variant="primary">重试全部失败项</flux:button>
            @endif
        </div>
    </section>

    <section class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="crm-card"><span class="text-xs text-zinc-500">批次状态</span><strong class="mt-1 block">{{ ['partial_failed' => '部分失败', 'failed' => '失败'][$run->status] ?? $run->status }}</strong></div>
        <div class="crm-card"><span class="text-xs text-zinc-500">代理商总数</span><strong class="mt-1 block">{{ $run->total_agents }}</strong></div>
        <div class="crm-card"><span class="text-xs text-zinc-500">成功数</span><strong class="mt-1 block text-emerald-700">{{ $run->processed_agents }}</strong></div>
        <div class="crm-card"><span class="text-xs text-zinc-500">失败数</span><strong class="mt-1 block text-red-700">{{ $run->failed_agents }}</strong></div>
    </section>

    <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <h3 class="font-semibold">当前仍未解决的失败项</h3>
        @if ($failures === [])
            <p class="mt-4 text-sm text-zinc-500">当前没有未解决的失败项，可能已被重试成功。</p>
        @else
            <div class="crm-table-wrap mt-4"><table class="crm-table">
                <thead><tr><th>代理商编号</th><th>代理商名称</th><th>代理商 ID</th><th>失败原因</th></tr></thead>
                <tbody>
                    @foreach ($failures as $failure)
                        <tr wire:key="settlement-failure-{{ $failure->agentId }}">
                            <td>{{ $failure->agentCode }}</td>
                            <td>{{ $failure->agentName }}</td>
                            <td>{{ $failure->agentId }}</td>
                            <td class="whitespace-pre-wrap">{{ $failure->reason }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        @endif
    </section>
</div>
