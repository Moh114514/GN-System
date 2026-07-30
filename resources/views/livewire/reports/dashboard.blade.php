<div class="crm-dashboard" wire:poll.{{ $refreshSeconds }}s="refreshDashboard">
    <section class="crm-dashboard-controls" aria-label="看板操作">
        <div class="crm-dashboard-range">
            <flux:select wire:model.live="preset" size="sm" aria-label="统计区间" class="w-32">
                <option value="today">今日</option>
                <option value="week">本周</option>
                <option value="month">本月</option>
                <option value="quarter">本季度</option>
                <option value="year">本年</option>
                <option value="custom">自定义</option>
            </flux:select>
            @if ($preset === 'custom')
                <flux:input wire:model="customFrom" type="date" size="sm" aria-label="开始日期" />
                <flux:input wire:model="customTo" type="date" size="sm" aria-label="结束日期" />
                <flux:button wire:click="applyCustomRange" size="sm" variant="primary">应用</flux:button>
            @endif
            @if ($snapshot !== [])
                <span class="crm-dashboard-updated">
                    {{ $snapshot['range']['label'] }} ·
                    {{ \Carbon\CarbonImmutable::parse($snapshot['generated_at'])->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s') }}
                </span>
            @endif
        </div>
        <div class="flex flex-wrap gap-1.5">
            <flux:button wire:click="refreshDashboard" size="sm" variant="ghost" icon="arrow-path">刷新</flux:button>
            <flux:button wire:click="export('pdf')" size="sm" variant="ghost">PDF</flux:button>
            <flux:button wire:click="export('html')" size="sm" variant="ghost">HTML</flux:button>
            <flux:button x-on:click="window.gnExportDashboardPng($refs.dashboard)" size="sm" variant="primary">PNG</flux:button>
        </div>
        @if ($rangeError)<p class="crm-dashboard-error">{{ $rangeError }}</p>@endif
    </section>

    @if ($snapshot !== [])
        @php
            $metricDefinitions = [
                ['revenue', '营收', true, 'banknotes', 'teal', 'M0,22 Q15,8 30,18 T60,12 T90,20 T110,8'],
                ['new_customers', '新增客户', false, 'users', 'teal', 'M0,20 Q15,14 30,16 T60,10 T90,14 T110,6'],
                ['pending_reminders', '待跟进提醒', false, 'bell-alert', 'amber', 'M0,10 Q15,18 30,12 T60,22 T90,16 T110,24'],
                ['promotion_fee', '代理商推广费', true, 'briefcase', 'blue', 'M0,24 Q15,16 30,20 T60,14 T90,18 T110,10'],
                ['repurchase_rate', '复购率', false, 'arrow-path', 'purple', 'M0,18 Q15,14 30,12 T60,16 T90,10 T110,6'],
            ];
            $lifecycleDefinitions = [
                'registered' => ['建档', 'clipboard-document', 'teal'],
                'appointed' => ['预约', 'calendar-days', 'blue'],
                'arrived' => ['到院', 'building-office-2', 'purple'],
                'followed_up' => ['回访', 'phone', 'amber'],
                'repeat' => ['复购', 'arrow-path', 'green'],
            ];
            $statusTones = [
                'registered' => 'gray',
                'potential' => 'gray',
                'interested' => 'blue',
                'quoted' => 'blue',
                'appointment' => 'blue',
                'appointed' => 'blue',
                'booked' => 'blue',
                'arrived' => 'purple',
                'treatment' => 'teal',
                'treated' => 'teal',
                'followup' => 'amber',
                'returned_home' => 'amber',
                'repeat' => 'green',
                'dormant' => 'gray',
                'lost' => 'red',
            ];
            $ranking = array_slice($snapshot['charts']['agent_promotion_ranking'], 0, 5);
            $rankingTotal = array_sum(array_column($ranking, 'value'));
            $rankingMax = max(1, ...array_column($ranking ?: [['value' => 0]], 'value'));
            $lifecycle = $snapshot['panels']['lifecycle'];
            $repeatLifecycle = collect($lifecycle)->firstWhere('key', 'repeat');
            $settlement = $snapshot['panels']['settlement_progress'];
        @endphp

        <div
            x-ref="dashboard"
            class="crm-dashboard-snapshot"
            data-dashboard-snapshot="{{ json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) }}"
        >
            <section class="crm-metrics" aria-label="核心指标">
                @foreach ($metricDefinitions as [$key, $label, $money, $icon, $tone, $spark])
                    @php
                        $metric = match ($key) {
                            'pending_reminders' => [
                                'value' => $snapshot['panels']['pending_reminders'],
                                'change' => null,
                            ],
                            'promotion_fee' => [
                                'value' => $snapshot['panels']['promotion_fee'],
                                'change' => null,
                            ],
                            'repurchase_rate' => [
                                'value' => data_get($snapshot, 'charts.repurchase_rate.0.value', 0),
                                'change' => null,
                            ],
                            default => $snapshot['metrics'][$key],
                        };
                    @endphp
                    <article class="crm-metric">
                        <span class="crm-metric-icon tone-{{ $tone }}"><flux:icon :name="$icon" /></span>
                        <span class="crm-metric-label">{{ $label }} <span title="当前统计区间真实数据">ⓘ</span></span>
                        <strong class="crm-number">
                            @if ($key === 'repurchase_rate')
                                {{ number_format($metric['value'], 1) }}%
                            @elseif ($money)
                                ₩ {{ number_format($metric['value']) }}
                            @else
                                {{ number_format($metric['value']) }}
                            @endif
                        </strong>
                        <span class="crm-delta {{ $metric['change'] === null ? '' : ($metric['change'] >= 0 ? 'is-positive' : 'is-negative') }}">
                            环比
                            @if ($metric['change'] === null)
                                —
                            @else
                                <b>{{ $metric['change'] >= 0 ? '↑' : '↓' }}</b>
                                {{ number_format(abs($metric['change']), 2) }}%
                            @endif
                        </span>
                        <svg class="crm-spark tone-{{ $tone }}" viewBox="0 0 110 32" aria-hidden="true">
                            <path d="{{ $spark }}" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                        </svg>
                    </article>
                @endforeach
            </section>

            <section class="crm-dashboard-grid crm-dashboard-grid-top">
                <article class="crm-card crm-trend-card">
                    <header class="crm-card-header">
                        <h2>月度营收与订单趋势</h2>
                        <div class="crm-tabs" aria-label="统计周期">
                            <button type="button" class="is-active">月度</button>
                        </div>
                    </header>
                    <div class="crm-chart-legend">
                        <span><i class="tone-teal"></i>营收（KRW）</span>
                        <span><i class="tone-blue is-round"></i>订单数（单）</span>
                    </div>
                    @if ($snapshot['panels']['monthly_revenue_orders'] !== [])
                        <div
                            class="crm-chart"
                            wire:key="dashboard-trend-{{ $snapshot['generated_at'] }}"
                            data-dashboard-chart="monthly_revenue_orders"
                            data-chart-values="{{ json_encode($snapshot['panels']['monthly_revenue_orders'], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) }}"
                        ></div>
                    @else
                        <div class="crm-panel-empty"><flux:icon name="chart-bar" />当前区间暂无成交数据</div>
                    @endif
                </article>

                <article class="crm-card">
                    <header class="crm-card-header">
                        <h2>代理商推广费排行</h2>
                        <a class="crm-card-link" href="{{ route('agents.index') }}" wire:navigate>查看全部 <span>›</span></a>
                    </header>
                    <div class="crm-rank-list">
                        @forelse ($ranking as $index => $agent)
                            @php
                                $percentage = $rankingTotal === 0 ? 0 : $agent['value'] / $rankingTotal * 100;
                                $width = $agent['value'] / $rankingMax * 100;
                                $tones = ['teal', 'blue', 'amber', 'purple', 'green'];
                            @endphp
                            <div class="crm-rank-item">
                                <span class="crm-rank-number {{ $index < 3 ? 'is-top' : '' }}">{{ $index + 1 }}</span>
                                <span class="crm-mini-logo tone-{{ $tones[$index % count($tones)] }}">{{ mb_substr($agent['key'], 0, 1) }}</span>
                                <span class="crm-rank-name">
                                    <strong>{{ $agent['key'] }}</strong>
                                    <span><i style="width: {{ number_format($width, 1, '.', '') }}%"></i></span>
                                </span>
                                <span class="crm-rank-value">
                                    <strong class="crm-number">{{ number_format($agent['value']) }}</strong>
                                    <small>{{ number_format($percentage, 1) }}%</small>
                                </span>
                            </div>
                        @empty
                            <div class="crm-panel-empty"><flux:icon name="briefcase" />当前区间暂无推广费</div>
                        @endforelse
                    </div>
                </article>

                <article class="crm-card">
                    <header class="crm-card-header">
                        <h2>客户生命周期概览</h2>
                    </header>
                    <div class="crm-funnel">
                        @foreach ($lifecycle as $stage)
                            @php([$stageLabel, $stageIcon, $stageTone] = $lifecycleDefinitions[$stage['key']])
                            <div class="crm-funnel-row">
                                <span class="crm-funnel-icon tone-{{ $stageTone }}"><flux:icon :name="$stageIcon" /></span>
                                <span class="crm-funnel-track">
                                    <i class="tone-{{ $stageTone }}" style="width: {{ max(0, min(100, $stage['percentage'])) }}%"><b>{{ $stageLabel }}</b></i>
                                </span>
                                <strong class="crm-number">{{ number_format($stage['value']) }}</strong>
                                <small>{{ number_format($stage['percentage'], 1) }}%</small>
                            </div>
                        @endforeach
                    </div>
                    <div class="crm-conversion">
                        <span>建档至复购转化率</span>
                        <strong>{{ number_format(data_get($repeatLifecycle, 'percentage', 0), 1) }}%</strong>
                    </div>
                </article>
            </section>

            <section class="crm-dashboard-grid crm-dashboard-grid-bottom">
                <article class="crm-card">
                    <header class="crm-card-header">
                        <h2>今日待办提醒 <span class="crm-pill tone-red">{{ count($snapshot['panels']['today_tasks']) }}</span></h2>
                        <a class="crm-card-link" href="{{ route('reminders.index') }}" wire:navigate>查看全部 <span>›</span></a>
                    </header>
                    <div class="crm-task-list">
                        @forelse ($snapshot['panels']['today_tasks'] as $task)
                            <div class="crm-task">
                                <time class="crm-number">{{ $task['time'] }}</time>
                                <span class="crm-task-avatar">{{ mb_substr($task['customer_name'], 0, 1) }}</span>
                                <strong>{{ $task['customer_name'] }} · {{ $task['title'] }}</strong>
                                <span class="crm-pill tone-{{ $task['priority'] >= 4 ? 'red' : ($task['priority'] >= 3 ? 'amber' : 'teal') }}">{{ $task['tag'] }}</span>
                            </div>
                        @empty
                            <div class="crm-panel-empty"><flux:icon name="check-circle" />今天没有待办提醒</div>
                        @endforelse
                    </div>
                </article>

                <article class="crm-card crm-customer-card">
                    <header class="crm-card-header">
                        <h2>最近客户记录</h2>
                        <a class="crm-card-link" href="{{ route('customers.index') }}" wire:navigate>查看全部 <span>›</span></a>
                    </header>
                    <div class="crm-table-wrap">
                        <table class="crm-table">
                            <thead>
                                <tr>
                                    <th>客户编号</th>
                                    <th>姓名</th>
                                    <th>来源</th>
                                    <th>当前状态</th>
                                    <th>建档日期</th>
                                    <th>负责人</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($snapshot['panels']['recent_customers'] as $customer)
                                    <tr>
                                        <td>
                                            <a class="crm-customer-id crm-number" href="{{ route('customers.show', $customer['id']) }}" wire:navigate>{{ $customer['code'] }}</a>
                                        </td>
                                        <td><strong>{{ $customer['name'] }}</strong></td>
                                        <td>{{ $customer['source_name'] }}</td>
                                        <td><span class="crm-pill tone-{{ $statusTones[$customer['status_key']] ?? 'gray' }}">{{ $customer['status_name'] }}</span></td>
                                        <td class="crm-number">{{ $customer['created_on'] }}</td>
                                        <td>{{ $customer['owner_name'] }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="crm-table-empty">尚无客户记录</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </article>

                <article class="crm-card">
                    <header class="crm-card-header">
                        <h2>最近月结进度</h2>
                        @if (auth()->user()->is_super_admin)
                            <a class="crm-card-link" href="{{ route('settlements.index') }}" wire:navigate>查看明细 <span>›</span></a>
                        @endif
                    </header>
                    <div class="crm-settlement">
                        <div class="crm-progress-ring">
                            <svg viewBox="0 0 130 130" aria-hidden="true">
                                <circle cx="65" cy="65" r="55" class="crm-ring-track" />
                                <circle
                                    cx="65"
                                    cy="65"
                                    r="55"
                                    class="crm-ring-value"
                                    style="stroke-dashoffset: {{ number_format(345.6 * (1 - min(100, max(0, $settlement['percentage'])) / 100), 2, '.', '') }}"
                                />
                            </svg>
                            <span><strong class="crm-number">{{ number_format($settlement['percentage'], 1) }}%</strong><small>已结算</small></span>
                        </div>
                        <dl>
                            <div><dt>已结算推广费</dt><dd class="crm-number">₩ {{ number_format($settlement['settled_amount']) }}</dd></div>
                            <div><dt>待审核推广费</dt><dd class="crm-number">₩ {{ number_format($settlement['review_amount']) }}</dd></div>
                            <div><dt>待确认推广费</dt><dd class="crm-number">₩ {{ number_format($settlement['pending_amount']) }}</dd></div>
                            <div><dt>推广费合计</dt><dd class="crm-number is-primary">₩ {{ number_format($settlement['expected_amount']) }}</dd></div>
                        </dl>
                    </div>
                    <div class="crm-settlement-foot">
                        <span>结算周期：{{ $settlement['period_start'] }} 至 {{ $settlement['period_end'] }}</span>
                        <span>快照口径：<strong>真实月结记录</strong></span>
                    </div>
                </article>
            </section>

            <footer class="crm-dashboard-footer">
                <div>
                    <span>状态说明</span>
                    <span><i class="tone-green"></i>复购</span>
                    <span><i class="tone-blue"></i>已预约</span>
                    <span><i class="tone-purple"></i>已到院</span>
                    <span><i class="tone-teal"></i>已施术</span>
                    <span><i class="tone-amber"></i>回访中</span>
                    <span><i class="tone-red"></i>已流失</span>
                    <span><i class="tone-gray"></i>建档</span>
                </div>
                <span>
                    数据更新于
                    {{ \Carbon\CarbonImmutable::parse($snapshot['generated_at'])->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s') }}
                </span>
            </footer>
        </div>
    @endif
</div>
