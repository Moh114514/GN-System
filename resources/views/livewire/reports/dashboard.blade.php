<div class="crm-dashboard" wire:poll.{{ $refreshSeconds }}s="refreshDashboard">
    <section class="crm-section-header">
        <div>
            <p class="crm-eyebrow">Report · 真实业务数据</p>
            <h2>数据看板</h2>
            <p>核心经营指标与业务趋势均来自系统实时数据。</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <flux:button wire:click="refreshDashboard" variant="ghost" icon="arrow-path">刷新</flux:button>
            <flux:button wire:click="export('pdf')" variant="ghost">PDF</flux:button>
            <flux:button wire:click="export('html')" variant="ghost">HTML</flux:button>
            <flux:button x-on:click="window.gnExportDashboardPng($refs.dashboard)" variant="primary">PNG</flux:button>
        </div>
    </section>

    <section class="crm-card crm-dashboard-filter">
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
                <p class="crm-dashboard-updated">
                    {{ $snapshot['range']['label'] }} · 最后刷新
                    {{ \Carbon\CarbonImmutable::parse($snapshot['generated_at'])->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s') }}
                </p>
            @endif
        </div>
        @if ($rangeError)<p class="crm-dashboard-error">{{ $rangeError }}</p>@endif
    </section>

    @if ($snapshot !== [])
        @php
            $metrics = [
                'new_customers' => ['新增客户', false, 'users', 'teal'],
                'completed_amount' => ['成交金额', true, 'banknotes', 'blue'],
                'revenue' => ['营收', true, 'chart-bar', 'purple'],
                'active_customers' => ['在跟进客户', false, 'user-group', 'green'],
                'overdue_customers' => ['待回访客户', false, 'bell-alert', 'amber'],
                'pending_settlement' => ['待结算金额', true, 'briefcase', 'red'],
            ];
            $charts = [
                'monthly_consumption' => ['月度消费趋势', '区间内已完成订单金额', 'wide'],
                'agent_promotion_ranking' => ['代理商推广费排行', '按推广费金额排序', ''],
                'monthly_promotion' => ['月度推广费趋势', '已核算推广费的月度变化', ''],
                'institution_revenue' => ['机构营收对比', '区间内各机构成交金额', ''],
                'grade_distribution' => ['当前等级分布', '启用代理商的当前等级', ''],
                'source_distribution' => ['客户来源分布', '区间内新增客户来源', ''],
                'repurchase_rate' => ['复购率', '至少两笔已完成订单客户占比', 'compact'],
                'followup_completion_rate' => ['跟进完成率', '已完成提醒占到期提醒总数', 'compact'],
            ];
        @endphp
        <div
            x-ref="dashboard"
            class="crm-dashboard-snapshot"
            data-dashboard-snapshot="{{ json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) }}"
        >
            <section class="crm-metrics crm-report-metrics" aria-label="核心指标">
                @foreach ($metrics as $key => [$label, $money, $icon, $tone])
                    @php($metric = $snapshot['metrics'][$key])
                    <article class="crm-metric">
                        <span class="crm-metric-icon tone-{{ $tone }}"><flux:icon :name="$icon" /></span>
                        <span class="crm-metric-label">{{ $label }}</span>
                        <strong class="crm-number">{{ $money ? '₩ '.number_format($metric['value']) : number_format($metric['value']) }}</strong>
                        <span class="crm-delta {{ $metric['change'] === null ? '' : ($metric['change'] >= 0 ? 'is-positive' : 'is-negative') }}">
                            环比
                            @if ($metric['change'] === null)
                                —
                            @else
                                <b>{{ $metric['change'] >= 0 ? '↑' : '↓' }}</b>
                                {{ number_format(abs($metric['change']), 2) }}%
                            @endif
                        </span>
                    </article>
                @endforeach
            </section>

            <section class="crm-report-chart-grid" aria-label="业务趋势图表">
                @foreach ($charts as $key => [$label, $description, $size])
                    <article class="crm-card crm-report-chart-card {{ $size === '' ? '' : 'is-'.$size }}">
                        <header class="crm-card-header">
                            <div>
                                <h2>{{ $label }}</h2>
                                <p>{{ $description }}</p>
                            </div>
                        </header>
                        @if ($snapshot['charts'][$key] !== [])
                            <div
                                class="crm-report-chart"
                                wire:key="dashboard-chart-{{ $key }}-{{ $snapshot['generated_at'] }}"
                                data-dashboard-chart="{{ $key }}"
                                data-chart-values="{{ json_encode($snapshot['charts'][$key], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) }}"
                            ></div>
                        @else
                            <div class="crm-report-chart-empty">
                                <flux:icon name="chart-bar" />
                                <span>当前区间暂无数据</span>
                            </div>
                        @endif
                    </article>
                @endforeach
            </section>
            <footer class="crm-dashboard-footer crm-report-footer">
                <span>数据范围：所有内部业务数据</span>
                <span>
                区间 {{ substr($snapshot['range']['from'], 0, 10) }} 至 {{ substr($snapshot['range']['to'], 0, 10) }} ·
                    快照生成时间
                    {{ \Carbon\CarbonImmutable::parse($snapshot['generated_at'])->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s') }}
                </span>
            </footer>
        </div>
    @endif
</div>
