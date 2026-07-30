<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>GN-System 数据看板</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: #18181b; font-size: 12px; }
        h1 { margin-bottom: 4px; } .meta { color: #71717a; margin-bottom: 18px; }
        .metrics { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
        .metrics td { border: 1px solid #d4d4d8; padding: 10px; width: 33%; }
        .label { color: #71717a; } .value { font-size: 18px; font-weight: bold; margin-top: 4px; }
        .charts { width: 100%; border-collapse: collapse; }
        .charts th, .charts td { border: 1px solid #d4d4d8; padding: 6px; text-align: left; }
        .chart-title { margin-top: 14px; font-size: 14px; font-weight: bold; }
        .bar { fill: #0f766e; } .bar-bg { fill: #f4f4f5; }
    </style>
</head>
<body>
    <?php
        $metricLabels = [
            'new_customers' => '新增客户', 'completed_amount' => '成交金额',
            'revenue' => '营收', 'active_customers' => '在跟进客户',
            'overdue_customers' => '待回访客户', 'pending_settlement' => '待结算金额',
        ];
        $chartLabels = [
            'agent_promotion_ranking' => '代理商推广费排行', 'monthly_promotion' => '月度推广费趋势',
            'grade_distribution' => '当前等级分布', 'source_distribution' => '客户来源分布',
            'monthly_consumption' => '月度消费趋势', 'repurchase_rate' => '复购率',
            'followup_completion_rate' => '跟进完成率', 'institution_revenue' => '机构营收对比',
        ];
    ?>
    <h1>GN-System 数据看板</h1>
    <p class="meta">
        区间：{{ $snapshot['range']['label'] }}（{{ $snapshot['range']['from'] }} 至 {{ $snapshot['range']['to'] }}）；
        生成时间：{{ $snapshot['generated_at'] }}
    </p>
    <table class="metrics">
        <?php foreach (array_chunk(array_keys($metricLabels), 3) as $keys): ?>
            <tr>
                <?php foreach ($keys as $key): ?>
                    <?php $metric = $snapshot['metrics'][$key]; ?>
                    <td>
                        <div class="label">{{ $metricLabels[$key] }}</div>
                        <div class="value">{{ number_format($metric['value']) }}</div>
                        <div>环比 {{ $metric['change'] === null ? '—' : number_format($metric['change'], 2).'%' }}</div>
                    </td>
                <?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
    </table>
    <?php foreach ($chartLabels as $key => $label): ?>
        <?php
            $rows = $snapshot['charts'][$key];
            $maximum = max([1, ...array_map(fn ($row) => (float) $row['value'], $rows)]);
        ?>
        <div class="chart-title">{{ $label }}</div>
        <svg width="720" height="{{ max(36, count($rows) * 28) }}" viewBox="0 0 720 {{ max(36, count($rows) * 28) }}">
            <?php foreach ($rows as $index => $row): ?>
                <rect class="bar-bg" x="160" y="{{ $index * 28 + 5 }}" width="480" height="16" rx="3" />
                <rect class="bar" x="160" y="{{ $index * 28 + 5 }}" width="{{ 480 * ((float) $row['value'] / $maximum) }}" height="16" rx="3" />
                <text x="0" y="{{ $index * 28 + 17 }}">{{ $row['key'] }}</text>
                <text x="650" y="{{ $index * 28 + 17 }}">{{ number_format($row['value'], 2) }}</text>
            <?php endforeach; ?>
        </svg>
        <table class="charts">
            <thead><tr><th>项目</th><th>数值</th></tr></thead>
            <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="2">暂无数据</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                    <tr><td>{{ $row['key'] }}</td><td>{{ $row['value'] }}</td></tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    <?php endforeach; ?>
</body>
</html>
