<div
    class="crm-dashboard"
    wire:poll.{{ $refreshSeconds }}s="refreshDashboard"
>
    <section class="crm-dashboard-controls" aria-label="{{ __('dashboard.controls.actions') }}">
        <div class="crm-dashboard-range">
            <flux:select wire:model.live="preset" size="sm" aria-label="{{ __('dashboard.controls.range') }}" class="crm-dashboard-preset">
                <option value="today">{{ __('dashboard.ranges.today') }}</option>
                <option value="week">{{ __('dashboard.ranges.week') }}</option>
                <option value="month">{{ __('dashboard.ranges.month') }}</option>
                <option value="quarter">{{ __('dashboard.ranges.quarter') }}</option>
                <option value="year">{{ __('dashboard.ranges.year') }}</option>
                <option value="custom">{{ __('dashboard.ranges.custom') }}</option>
            </flux:select>
            @if ($preset === 'custom')
                <div class="crm-dashboard-custom-range" role="group" aria-label="{{ __('dashboard.controls.custom_range') }}">
                    <x-date-time-picker
                        id="dashboard-custom-from"
                        wire:model="customFrom"
                        :value="$customFrom"
                        :label="__('dashboard.controls.start_date')"
                        class="crm-dashboard-date-input"
                        size="sm"
                    />
                    <span class="crm-dashboard-date-separator" aria-hidden="true">—</span>
                    <x-date-time-picker
                        id="dashboard-custom-to"
                        wire:model="customTo"
                        :value="$customTo"
                        :label="__('dashboard.controls.end_date')"
                        class="crm-dashboard-date-input"
                        size="sm"
                    />
                    <flux:button wire:click="applyCustomRange" size="sm" variant="primary">{{ __('dashboard.controls.apply') }}</flux:button>
                </div>
            @endif
            @if ($snapshot !== [])
                <span class="crm-dashboard-updated">
                    {{ $snapshot['range']['label'] }} ·
                    {{ \Carbon\CarbonImmutable::parse($snapshot['generated_at'])->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s') }}
                </span>
            @endif
        </div>
        <div class="flex flex-wrap gap-1.5">
            <flux:button wire:click="refreshDashboard" size="sm" variant="ghost" icon="arrow-path">{{ __('dashboard.controls.refresh') }}</flux:button>
            <flux:button wire:click="export('pdf')" size="sm" variant="ghost">PDF</flux:button>
            <flux:button wire:click="export('html')" size="sm" variant="ghost">HTML</flux:button>
        </div>
        @if ($rangeError)<p class="crm-dashboard-error">{{ $rangeError }}</p>@endif
    </section>

    @if ($snapshot !== [])
        @php
            $metricDefinitions = [
                ['revenue', __('dashboard.metrics.revenue'), true, 'banknotes', 'teal', 'M0,22 Q15,8 30,18 T60,12 T90,20 T110,8'],
                ['new_customers', __('dashboard.metrics.new_customers'), false, 'users', 'teal', 'M0,20 Q15,14 30,16 T60,10 T90,14 T110,6'],
                ['promotion_fee', __('dashboard.metrics.promotion_fee'), true, 'briefcase', 'blue', 'M0,24 Q15,16 30,20 T60,14 T90,18 T110,10'],
                ['repurchase_rate', __('dashboard.metrics.repurchase_rate'), false, 'arrow-path', 'purple', 'M0,18 Q15,14 30,12 T60,16 T90,10 T110,6'],
            ];
            $ranking = array_slice($snapshot['charts']['agent_promotion_ranking'], 0, 5);
            $rankingTotal = array_sum(array_column($ranking, 'value'));
            $rankingMax = max(1, ...array_column($ranking ?: [['value' => 0]], 'value'));
            $settlement = $snapshot['panels']['settlement_progress'];
            $rangeFrom = \Carbon\CarbonImmutable::parse($snapshot['range']['from'])->setTimezone('Asia/Shanghai')->toDateString();
            $rangeTo = \Carbon\CarbonImmutable::parse($snapshot['range']['to'])->setTimezone('Asia/Shanghai')->toDateString();
            $reportRange = ['completedFrom' => $rangeFrom, 'completedTo' => $rangeTo];
            $metricLinks = [
                'revenue' => route('reports.search', $reportRange),
                'new_customers' => route('customers.index', ['createdFrom' => $rangeFrom, 'createdTo' => $rangeTo]),
                'promotion_fee' => auth()->user()->is_super_admin ? route('settlements.index') : null,
                'repurchase_rate' => route('reports.search', $reportRange),
            ];
        @endphp

        <div
            class="crm-dashboard-snapshot"
        >
            <section class="crm-metrics" aria-label="{{ __('dashboard.metrics.core') }}">
                @foreach ($metricDefinitions as [$key, $label, $money, $icon, $tone, $spark])
                    @php
                        $metric = match ($key) {
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
                        $metricLink = $metricLinks[$key] ?? null;
                    @endphp
                    @if ($metricLink)
                        <a class="crm-metric" href="{{ $metricLink }}" wire:navigate aria-label="{{ $label }}">
                    @else
                        <article class="crm-metric">
                    @endif
                        <span class="crm-metric-icon tone-{{ $tone }}"><flux:icon :name="$icon" /></span>
                        <span class="crm-metric-label">{{ $label }} <span title="{{ __('dashboard.metrics.actual') }}">ⓘ</span></span>
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
                            {{ __('dashboard.metrics.period_change') }}
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
                    @if ($metricLink)
                        </a>
                    @else
                        </article>
                    @endif
                @endforeach
            </section>

            <section class="crm-dashboard-grid crm-dashboard-grid-top">
                <article class="crm-card crm-trend-card">
                    <header class="crm-card-header">
                        <h2>{{ __('dashboard.panels.trend') }}</h2>
                    </header>
                    <a class="crm-card-link" href="{{ route('reports.search', $reportRange) }}" wire:navigate>{{ __('dashboard.panels.view_details') }} <span>›</span></a>
                    <div class="crm-chart-legend">
                        <span><i class="tone-teal"></i>{{ __('dashboard.panels.revenue_krw') }}</span>
                        <span><i class="tone-blue is-round"></i>{{ __('dashboard.panels.orders_count') }}</span>
                    </div>
                    @if ($snapshot['panels']['monthly_revenue_orders'] !== [])
                        <div
                            class="crm-chart"
                            wire:key="dashboard-trend-{{ $snapshot['generated_at'] }}"
                            data-dashboard-chart="monthly_revenue_orders"
                            data-chart-values="{{ json_encode($snapshot['panels']['monthly_revenue_orders'], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) }}"
                        ></div>
                    @else
                        <div class="crm-panel-empty"><flux:icon name="chart-bar" />{{ __('dashboard.panels.no_transactions') }}</div>
                    @endif
                </article>

                <article class="crm-card">
                    <header class="crm-card-header">
                        <h2>{{ __('dashboard.panels.promotion_ranking') }}</h2>
                        @if (auth()->user()->is_super_admin)
                            <a class="crm-card-link" href="{{ route('agents.index') }}" wire:navigate>{{ __('dashboard.panels.view_all') }} <span>›</span></a>
                        @endif
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
                                    @if (auth()->user()->is_super_admin && isset($agent['id']))
                                        <a class="font-semibold text-teal-700 hover:underline" href="{{ route('agents.show', $agent['id']) }}" wire:navigate>{{ $agent['key'] }}</a>
                                    @else
                                        <strong>{{ $agent['key'] }}</strong>
                                    @endif
                                    <span><i style="width: {{ number_format($width, 1, '.', '') }}%"></i></span>
                                </span>
                                <span class="crm-rank-value">
                                    <strong class="crm-number">{{ number_format($agent['value']) }}</strong>
                                    <small>{{ number_format($percentage, 1) }}%</small>
                                </span>
                            </div>
                        @empty
                            <div class="crm-panel-empty"><flux:icon name="briefcase" />{{ __('dashboard.panels.no_promotion_fee') }}</div>
                        @endforelse
                    </div>
                </article>

            </section>

            <section class="crm-dashboard-grid crm-dashboard-grid-bottom">
                <article class="crm-card">
                    <header class="crm-card-header">
                        <h2>{{ __('dashboard.panels.settlement') }}</h2>
                        @if (auth()->user()->is_super_admin)
                            <a class="crm-card-link" href="{{ route('settlements.index') }}" wire:navigate>{{ __('dashboard.panels.view_details') }} <span>›</span></a>
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
                            <span><strong class="crm-number">{{ number_format($settlement['percentage'], 1) }}%</strong><small>{{ __('dashboard.panels.settled') }}</small></span>
                        </div>
                        <dl>
                            <div><dt>{{ __('dashboard.panels.settled_fee') }}</dt><dd class="crm-number">₩ {{ number_format($settlement['settled_amount']) }}</dd></div>
                            <div><dt>{{ __('dashboard.panels.review_fee') }}</dt><dd class="crm-number">₩ {{ number_format($settlement['review_amount']) }}</dd></div>
                            <div><dt>{{ __('dashboard.panels.pending_fee') }}</dt><dd class="crm-number">₩ {{ number_format($settlement['pending_amount']) }}</dd></div>
                            <div><dt>{{ __('dashboard.panels.total_fee') }}</dt><dd class="crm-number is-primary">₩ {{ number_format($settlement['expected_amount']) }}</dd></div>
                        </dl>
                    </div>
                    <div class="crm-settlement-foot">
                        <span>{{ __('dashboard.panels.settlement_period', ['from' => $settlement['period_start'], 'to' => $settlement['period_end']]) }}</span>
                        <span>{{ __('dashboard.panels.snapshot_basis') }}<strong>{{ __('dashboard.panels.real_settlement_records') }}</strong></span>
                    </div>
                </article>
            </section>

            <footer class="crm-dashboard-footer">
                <div>
                    <span>{{ __('dashboard.panels.status_legend') }}</span>
                    <span><i class="tone-blue"></i>{{ __('dashboard.statuses.appointed') }}</span>
                    <span><i class="tone-purple"></i>{{ __('dashboard.statuses.arrived') }}</span>
                    <span><i class="tone-teal"></i>{{ __('dashboard.statuses.treated') }}</span>
                </div>
                <span>
                    {{ __('dashboard.panels.updated_at') }}
                    {{ \Carbon\CarbonImmutable::parse($snapshot['generated_at'])->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s') }}
                </span>
            </footer>
        </div>
    @endif
</div>
