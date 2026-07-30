<div wire:poll.{{ $refreshSeconds }}s="refreshDashboard">
    <section class="crm-section-header">
        <div>
            <p class="crm-eyebrow">Report · 真实业务数据</p>
            <h2>数据看板</h2>
            <p>所有内部用户查看全量数据；聚合缓存最长 5 分钟，手动刷新会立即重新计算。</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <flux:button wire:click="refreshDashboard" variant="ghost" icon="arrow-path">刷新</flux:button>
            <flux:button wire:click="export('pdf')" variant="ghost">PDF</flux:button>
            <flux:button wire:click="export('html')" variant="ghost">HTML</flux:button>
            <flux:button x-on:click="window.gnExportDashboardPng($refs.dashboard)" variant="primary">PNG</flux:button>
        </div>
    </section>

    <section class="mb-6 rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex flex-wrap items-end gap-3">
            <flux:select wire:model.live="preset" label="统计区间" class="w-40">
                <option value="today">今日</option>
                <option value="week">本周</option>
                <option value="month">本月</option>
                <option value="quarter">本季度</option>
                <option value="year">本年</option>
                <option value="custom">自定义</option>
            </flux:select>
            @if ($preset === 'custom')
                <flux:input wire:model="customFrom" type="date" label="开始日期" />
                <flux:input wire:model="customTo" type="date" label="结束日期" />
                <flux:button wire:click="applyCustomRange" variant="primary">应用</flux:button>
            @endif
            @if ($snapshot !== [])
                <p class="pb-2 text-sm text-zinc-500">
                    {{ $snapshot['range']['label'] }} · 最后刷新
                    {{ \Carbon\CarbonImmutable::parse($snapshot['generated_at'])->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s') }}
                </p>
            @endif
        </div>
        @if ($rangeError)<p class="mt-3 text-sm text-red-600">{{ $rangeError }}</p>@endif
    </section>

    @if ($snapshot !== [])
        @php
            $metricLabels = [
                'new_customers' => ['新增客户', false],
                'completed_amount' => ['成交金额', true],
                'revenue' => ['营收', true],
                'active_customers' => ['在跟进客户', false],
                'overdue_customers' => ['待回访客户', false],
                'pending_settlement' => ['待结算金额', true],
            ];
            $chartLabels = [
                'agent_promotion_ranking' => '代理商推广费排行',
                'monthly_promotion' => '月度推广费趋势',
                'grade_distribution' => '当前等级分布',
                'source_distribution' => '客户来源分布',
                'monthly_consumption' => '月度消费趋势',
                'repurchase_rate' => '复购率',
                'followup_completion_rate' => '跟进完成率',
                'institution_revenue' => '机构营收对比',
            ];
        @endphp
        <div x-ref="dashboard" data-dashboard-snapshot="{{ json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) }}">
            <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($metricLabels as $key => [$label, $money])
                    @php($metric = $snapshot['metrics'][$key])
                    <article class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                        <p class="text-sm text-zinc-500">{{ $label }}</p>
                        <strong class="mt-2 block text-2xl">{{ $money ? '₩ '.number_format($metric['value']) : number_format($metric['value']) }}</strong>
                        <span class="mt-2 block text-sm {{ ($metric['change'] ?? 0) >= 0 ? 'text-emerald-600' : 'text-red-600' }}">
                            环比 {{ $metric['change'] === null ? '—' : (($metric['change'] >= 0 ? '+' : '').number_format($metric['change'], 2).'%') }}
                        </span>
                    </article>
                @endforeach
            </section>

            <section class="mt-6 grid gap-5 xl:grid-cols-2">
                @foreach ($chartLabels as $key => $label)
                    <article class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                        <h3 class="font-semibold">{{ $label }}</h3>
                        <div
                            class="mt-4 h-72"
                            data-dashboard-chart="{{ $key }}"
                            data-chart-values="{{ json_encode($snapshot['charts'][$key], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) }}"
                        ></div>
                        @if ($snapshot['charts'][$key] === [])
                            <p class="py-12 text-center text-sm text-zinc-500">当前区间暂无数据。</p>
                        @endif
                    </article>
                @endforeach
            </section>
            <p class="mt-5 text-right text-xs text-zinc-500">
                区间 {{ substr($snapshot['range']['from'], 0, 10) }} 至 {{ substr($snapshot['range']['to'], 0, 10) }} ·
                快照生成时间 {{ $snapshot['generated_at'] }}
            </p>
        </div>
    @endif
</div>
