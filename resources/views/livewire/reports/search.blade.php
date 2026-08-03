<div>
    <section class="crm-section-header">
        <div>
            <h2 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-50">多维查询</h2>
            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">可按九个维度组合查询；护照号仅支持精确匹配，全程加密、不明文展示。</p>
        </div>
        <flux:button wire:click="downloadExport" wire:loading.attr="disabled" wire:target="downloadExport" variant="primary" icon="arrow-down-tray">导出 Excel</flux:button>
    </section>

    @if (session('status'))
        <div class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ session('error') }}</div>
    @endif

    <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <flux:input wire:model.live.debounce.400ms="completedFrom" type="date" label="成交日期起" />
            <flux:input wire:model.live.debounce.400ms="completedTo" type="date" label="成交日期止" />
            <flux:input wire:model.live.debounce.400ms="timeFrom" type="time" label="成交时段起" />
            <flux:input wire:model.live.debounce.400ms="timeTo" type="time" label="成交时段止" />
            <flux:select wire:model.live="customerId" label="客户">
                <option value="">全部客户</option>
                @foreach ($options['customers'] as $customer)
                    <option value="{{ $customer['id'] }}">{{ $customer['name'] }}</option>
                @endforeach
            </flux:select>
            <flux:select wire:model.live="agentId" label="代理商">
                <option value="">全部代理商</option>
                @foreach ($options['agents'] as $agent)
                    <option value="{{ $agent['id'] }}">{{ $agent['name'] }}</option>
                @endforeach
            </flux:select>
            <flux:input wire:model.live.debounce.400ms="projectName" label="施术项目" />
            <flux:select wire:model.live="institutionId" label="机构">
                <option value="">全部机构</option>
                @foreach ($options['institutions'] as $institution)
                    <option value="{{ $institution['id'] }}">{{ $institution['name'] }}</option>
                @endforeach
            </flux:select>
            <flux:input wire:model.live.debounce.400ms="translatorName" label="翻译姓名" />
            <flux:input wire:model.live.debounce.400ms="amountMin" type="number" min="0" label="成交金额下限（KRW）" />
            <flux:input wire:model.live.debounce.400ms="amountMax" type="number" min="0" label="成交金额上限（KRW）" />
            <flux:input wire:model.live.debounce.400ms="passport" label="护照号（精确）" autocomplete="off" />
            <flux:select wire:model.live="sortField" label="排序字段">
                <option value="completed_at">成交时间</option>
                <option value="customer">客户</option>
                <option value="agent">代理商</option>
                <option value="project">项目</option>
                <option value="institution">机构</option>
                <option value="amount">金额</option>
            </flux:select>
            <flux:select wire:model.live="sortDirection" label="排序方向">
                <option value="desc">降序</option>
                <option value="asc">升序</option>
            </flux:select>
        </div>
        <div class="mt-4">
            <flux:button wire:click="clearFilters" variant="ghost">清空筛选</flux:button>
        </div>
    </section>

    <section class="mt-6 rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h3 class="font-semibold">查询结果</h3>
                <p class="text-sm text-zinc-500">共 {{ number_format($result['page']->total) }} 条 · 查询耗时 {{ number_format($result['page']->queryMilliseconds, 2) }} ms · 每页 {{ $result['page']->perPage }} 条</p>
            </div>
            <span class="text-sm text-zinc-500">第 {{ $result['page']->currentPage }} / {{ max(1, $result['page']->lastPage) }} 页</span>
        </div>
        <div class="crm-table-wrap mt-4">
            <table class="crm-table">
                <thead>
                    <tr><th>成交时间</th><th>客户</th><th>代理商</th><th>项目</th><th>机构</th><th>翻译</th><th>金额 KRW</th></tr>
                </thead>
                <tbody>
                    @forelse ($result['rows'] as $row)
                        <tr>
                            <td>{{ $row['completed_at'] }} @if ($row['completion_precision'] === 'date')<span class="text-xs text-zinc-400">日期精度</span>@endif</td>
                            <td><a href="{{ route('customers.show', $row['customer_id']) }}" class="font-semibold text-teal-700 hover:underline" wire:navigate>{{ $row['customer'] }}</a></td>
                            <td>{{ $row['agent'] }}</td>
                            <td>{{ $row['project'] }}</td>
                            <td>{{ $row['institution'] }}</td>
                            <td>{{ $row['translator'] ?: '—' }}</td>
                            <td>₩ {{ number_format($row['amount_krw']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-10 text-center text-zinc-500">没有符合当前条件的已完成订单。</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4 flex justify-end gap-2">
            <flux:button wire:click="previousPage" variant="ghost" :disabled="$result['page']->currentPage <= 1">上一页</flux:button>
            <flux:button wire:click="nextPage({{ $result['page']->lastPage }})" variant="ghost" :disabled="$result['page']->currentPage >= $result['page']->lastPage">下一页</flux:button>
        </div>
    </section>

    <section class="mt-6 rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900" @if ($recentExports->contains(fn ($export) => in_array($export->status, ['queued', 'generating'], true))) wire:poll.3s @endif>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h3 class="font-semibold">最近导出</h3>
                <p class="mt-1 text-sm text-zinc-500">最近生成的 Excel 文件会保留 24 小时。</p>
            </div>
            <span class="text-sm text-zinc-500">{{ $recentExports->count() }} 条记录</span>
        </div>
        <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                @forelse ($recentExports as $export)
                    <div class="rounded-xl border border-zinc-200 p-4 text-sm dark:border-zinc-700">
                        <div class="flex items-center justify-between gap-3">
                            <span>{{ $export->created_at?->format('Y-m-d H:i') }} · {{ $export->status }}</span>
                            @if ($export->status === 'completed' && $export->expires_at->isFuture())
                                <a href="{{ route('reports.exports.download', $export) }}" class="font-semibold text-teal-700">下载</a>
                            @elseif ($export->status === 'failed' && $export->expires_at->isFuture())
                                <flux:button wire:click="retryExport('{{ $export->id }}')" variant="ghost" size="sm">重试</flux:button>
                            @endif
                        </div>
                        @if ($export->failure_reason)<p class="mt-1 text-red-600">{{ $export->failure_reason }}</p>@endif
                        @if ($export->sha256)<p class="mt-1 break-all font-mono text-xs text-zinc-400">SHA-256 {{ $export->sha256 }}</p>@endif
                    </div>
                @empty
                    <p class="text-sm text-zinc-500">尚无导出任务。</p>
                @endforelse
        </div>
    </section>
</div>
