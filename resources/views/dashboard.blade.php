@php
    $metrics = [
        ['本月营收', '¥ 3,820,680', '较上月', '18.6%', 'up', 'banknotes', 'teal', 'M0,22 Q15,8 30,18 T60,12 T90,20 T110,8'],
        ['新增客户', '286', '较上月', '12.3%', 'up', 'users', 'teal', 'M0,20 Q15,14 30,16 T60,10 T90,14 T110,6'],
        ['待跟进提醒', '58', '较上月', '5.4%', 'down', 'bell-alert', 'amber', 'M0,10 Q15,18 30,12 T60,22 T90,16 T110,24'],
        ['代理商结算额', '¥ 1,256,300', '较上月', '9.7%', 'up', 'briefcase', 'blue', 'M0,24 Q15,16 30,20 T60,14 T90,18 T110,10'],
        ['复购率', '32.7%', '较上月', '2.8bp', 'up', 'arrow-path', 'purple', 'M0,18 Q15,14 30,12 T60,16 T90,10 T110,6'],
    ];

    $agents = [
        ['美', '美之源医疗咨询', '286,300', '22.8%', 95, 'teal'],
        ['颜', '颜选国际医美', '215,600', '17.2%', 75, 'blue'],
        ['皓', '皓美生物科技', '182,400', '14.5%', 64, 'amber'],
        ['星', '星耀医美咨询', '148,900', '11.9%', 52, 'purple'],
        ['悦', '悦美汇管理咨询', '112,700', '9.0%', 40, 'green'],
    ];

    $lifecycle = [
        ['建档', '1,856', '100%', 100, 'clipboard-document', 'teal'],
        ['预约', '1,238', '66.7%', 66.7, 'calendar-days', 'blue'],
        ['到院', '892', '48.0%', 48, 'building-office-2', 'purple'],
        ['回访', '614', '33.1%', 33.1, 'phone', 'amber'],
        ['复购', '327', '17.6%', 17.6, 'arrow-path', 'green'],
    ];

    $tasks = [
        ['09:30', '李', '李思雨 · 瑞蓝玻尿酸（术后回访）', '术后第7天', 'teal'],
        ['10:00', '王', '王佳怡 · 热玛吉（效果跟进）', '术后第7天', 'teal'],
        ['11:00', '张', '张紫涵 · 会员生日提醒（5月）', '生日提醒', 'amber'],
        ['14:00', '陈', '陈雅婷 · 上次消费45天（复购窗口）', '复购窗口', 'purple'],
        ['16:00', '刘', '刘子墨 · 未知客咨询回访', '新客跟进', 'gray'],
    ];

    $customers = [
        ['C250528001', '李思雨', '美之源医疗咨询', '已施术', 'teal', '2025-05-28', '张佳怡'],
        ['C250527089', '王佳怡', '颜选国际医美', '已预约', 'blue', '2025-05-30', '刘畅'],
        ['C250526072', '张紫涵', '皓美生物科技', '合作中', 'green', '2025-06-01', '周婷'],
        ['C250525056', '陈雅婷', '星耀医美咨询', '到院', 'purple', '2025-05-25', '赵敏'],
        ['C250524033', '刘子墨', '悦美汇管理咨询', '建档', 'gray', '2025-05-24', '李想'],
    ];
@endphp

<x-layouts::app title="CRM 管理系统">
    <div class="crm-dashboard">
        <div class="crm-demo-notice">
            <span><flux:icon.information-circle /> 当前展示为演示数据</span>
            <span>业务模块接入后将自动替换为实时数据</span>
        </div>

        <section class="crm-metrics" aria-label="核心指标">
            @foreach ($metrics as [$label, $value, $compare, $delta, $direction, $icon, $tone, $spark])
                <article class="crm-metric">
                    <span class="crm-metric-icon tone-{{ $tone }}"><flux:icon :name="$icon" /></span>
                    <span class="crm-metric-label">{{ $label }} <span title="演示统计口径">ⓘ</span></span>
                    <strong class="crm-number">{{ $value }}</strong>
                    <span class="crm-delta is-{{ $direction }}">
                        {{ $compare }}
                        <b>{{ $direction === 'up' ? '↑' : '↓' }}</b>
                        {{ $delta }}
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
                        <button type="button" disabled>季度</button>
                        <button type="button" disabled>年度</button>
                    </div>
                </header>
                <div class="crm-chart-legend">
                    <span><i class="tone-teal"></i>营收（万元）</span>
                    <span><i class="tone-blue is-round"></i>订单数（单）</span>
                </div>
                <svg class="crm-chart" viewBox="0 0 540 220" role="img" aria-label="2024年12月至2025年5月营收与订单趋势图">
                    @foreach ([0, 100, 200, 300, 400, 500] as $tick)
                        @php
                            $y = 192 - ($tick / 500 * 176);
                        @endphp
                        <line x1="38" y1="{{ $y }}" x2="502" y2="{{ $y }}" class="crm-chart-grid" />
                        <text x="31" y="{{ $y + 4 }}" text-anchor="end">{{ $tick }}</text>
                    @endforeach

                    @foreach ([180, 220, 260, 290, 340, 428] as $index => $revenue)
                        @php
                            $x = 60 + ($index * 77.3);
                            $height = $revenue / 500 * 176;
                            $y = 192 - $height;
                        @endphp
                        <rect x="{{ $x }}" y="{{ $y }}" width="32" height="{{ $height }}" rx="4" class="crm-chart-bar">
                            <animate attributeName="height" from="0" to="{{ $height }}" dur=".6s" begin="{{ $index * 0.06 }}s" fill="freeze" />
                            <animate attributeName="y" from="192" to="{{ $y }}" dur=".6s" begin="{{ $index * 0.06 }}s" fill="freeze" />
                        </rect>
                    @endforeach

                    <path d="M76,93.4 L153.3,79.4 L230.6,82.9 L307.9,58.2 L385.2,52.9 L462.5,23" class="crm-chart-line" />
                    @foreach ([[76,93.4], [153.3,79.4], [230.6,82.9], [307.9,58.2], [385.2,52.9], [462.5,23]] as [$x, $y])
                        <circle cx="{{ $x }}" cy="{{ $y }}" r="4" class="crm-chart-point" />
                    @endforeach

                    @foreach (['2024-12', '2025-01', '2025-02', '2025-03', '2025-04', '2025-05'] as $index => $month)
                        <text x="{{ 76 + ($index * 77.3) }}" y="213" text-anchor="middle">{{ $month }}</text>
                    @endforeach
                </svg>
            </article>

            <article class="crm-card">
                <header class="crm-card-header">
                    <h2>代理商推广费排行</h2>
                    <span class="crm-card-link">查看全部 <span>›</span></span>
                </header>
                <div class="crm-rank-list">
                    @foreach ($agents as $index => [$initial, $name, $amount, $percentage, $width, $tone])
                        <div class="crm-rank-item">
                            <span class="crm-rank-number {{ $index < 3 ? 'is-top' : '' }}">{{ $index + 1 }}</span>
                            <span class="crm-mini-logo tone-{{ $tone }}">{{ $initial }}</span>
                            <span class="crm-rank-name">
                                <strong>{{ $name }}</strong>
                                <span><i style="width: {{ $width }}%"></i></span>
                            </span>
                            <span class="crm-rank-value"><strong class="crm-number">{{ $amount }}</strong><small>{{ $percentage }}</small></span>
                        </div>
                    @endforeach
                </div>
            </article>

            <article class="crm-card">
                <header class="crm-card-header">
                    <h2>客户生命周期概览</h2>
                </header>
                <div class="crm-funnel">
                    @foreach ($lifecycle as [$label, $total, $percentage, $width, $icon, $tone])
                        <div class="crm-funnel-row">
                            <span class="crm-funnel-icon tone-{{ $tone }}"><flux:icon :name="$icon" /></span>
                            <span class="crm-funnel-track">
                                <i class="tone-{{ $tone }}" style="width: {{ $width }}%"><b>{{ $label }}</b></i>
                            </span>
                            <strong class="crm-number">{{ $total }}</strong>
                            <small>{{ $percentage }}</small>
                        </div>
                    @endforeach
                </div>
                <div class="crm-conversion"><span>整体转化率</span><strong>17.6%</strong></div>
            </article>
        </section>

        <section class="crm-dashboard-grid crm-dashboard-grid-bottom">
            <article class="crm-card">
                <header class="crm-card-header">
                    <h2>今日待办提醒 <span class="crm-pill tone-red">{{ count($tasks) }}</span></h2>
                    <span class="crm-card-link">查看全部 <span>›</span></span>
                </header>
                <div class="crm-task-list">
                    @foreach ($tasks as [$time, $initial, $title, $tag, $tone])
                        <div class="crm-task">
                            <time class="crm-number">{{ $time }}</time>
                            <span class="crm-task-avatar">{{ $initial }}</span>
                            <strong>{{ $title }}</strong>
                            <span class="crm-pill tone-{{ $tone }}">{{ $tag }}</span>
                        </div>
                    @endforeach
                </div>
            </article>

            <article class="crm-card crm-customer-card">
                <header class="crm-card-header">
                    <h2>最近客户记录</h2>
                    <span class="crm-card-link">查看全部 <span>›</span></span>
                </header>
                <div class="crm-table-wrap">
                    <table class="crm-table">
                        <thead>
                            <tr>
                                <th>客户编号</th>
                                <th>姓名</th>
                                <th>代理商</th>
                                <th>当前状态</th>
                                <th>到店日期</th>
                                <th>跟进人</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($customers as [$id, $name, $agent, $status, $tone, $date, $owner])
                                <tr>
                                    <td class="crm-customer-id crm-number">{{ $id }}</td>
                                    <td>{{ $name }}</td>
                                    <td>{{ $agent }}</td>
                                    <td><span class="crm-pill tone-{{ $tone }}">{{ $status }}</span></td>
                                    <td class="crm-number">{{ $date }}</td>
                                    <td>{{ $owner }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </article>

            <article class="crm-card">
                <header class="crm-card-header">
                    <h2>本月月结进度</h2>
                    <span class="crm-card-link">查看明细 <span>›</span></span>
                </header>
                <div class="crm-settlement">
                    <div class="crm-progress-ring">
                        <svg viewBox="0 0 130 130" aria-hidden="true">
                            <circle cx="65" cy="65" r="55" class="crm-ring-track" />
                            <circle cx="65" cy="65" r="55" class="crm-ring-value" />
                        </svg>
                        <span><strong class="crm-number">78%</strong><small>已完成</small></span>
                    </div>
                    <dl>
                        <div><dt>已结算金额</dt><dd class="crm-number">¥ 1,256,300</dd></div>
                        <div><dt>待审核金额</dt><dd class="crm-number">¥ 346,800</dd></div>
                        <div><dt>待结算金额</dt><dd class="crm-number">¥ 0</dd></div>
                        <div><dt>预计可结金额</dt><dd class="crm-number is-primary">¥ 1,603,100</dd></div>
                    </dl>
                </div>
                <div class="crm-settlement-foot"><span>结算周期：05.01 - 05.31</span><span>距月结截止：<strong>2 天</strong></span></div>
            </article>
        </section>

        <footer class="crm-dashboard-footer">
            <div>
                <span>状态说明</span>
                <span><i class="tone-green"></i>合作中</span>
                <span><i class="tone-blue"></i>已预约</span>
                <span><i class="tone-purple"></i>到院</span>
                <span><i class="tone-teal"></i>已施术</span>
                <span><i class="tone-amber"></i>已结算</span>
                <span><i class="tone-red"></i>待审核</span>
                <span><i class="tone-gray"></i>建档</span>
            </div>
            <span>演示数据更新于 2025-05-31 09:30:00</span>
        </footer>
    </div>
</x-layouts::app>
