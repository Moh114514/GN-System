<!doctype html>
<html lang="{{ str_replace('_', '-', $snapshot['locale'] ?? app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <title>GN-System {{ __('dashboard.export.title') }}</title>
    <style>
        @if ($pdfRegularFontPath !== null)
            @font-face {
                font-family: "GN CJK";
                font-style: normal;
                font-weight: 400;
                src: url("file://{{ $pdfRegularFontPath }}") format("truetype");
            }
            @font-face {
                font-family: "GN CJK";
                font-style: normal;
                font-weight: 700;
                src: url("file://{{ $pdfBoldFontPath }}") format("truetype");
            }
        @endif
        body {
            font-family: "GN CJK", "Microsoft YaHei", "PingFang SC", "Noto Sans CJK SC", DejaVu Sans, sans-serif;
            color: #18181b;
            font-size: 12px;
        }
        h1 { margin-bottom: 4px; font-weight: normal; } .meta { color: #71717a; margin-bottom: 18px; }
        .metrics { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
        .metrics td { border: 1px solid #d4d4d8; padding: 10px; width: 33%; }
        .label { color: #71717a; } .value { font-size: 18px; font-weight: normal; margin-top: 4px; }
        .charts { width: 100%; border-collapse: collapse; }
        .charts th, .charts td { border: 1px solid #d4d4d8; padding: 6px; text-align: left; }
        .charts th { font-weight: normal; }
        .chart-title { margin-top: 14px; font-size: 14px; font-weight: normal; }
        .bar { fill: #0f766e; } .bar-bg { fill: #f4f4f5; }
    </style>
</head>
<body>
    <?php
        $metricLabels = __('dashboard.export.metric_labels');
        $chartLabels = __('dashboard.export.chart_labels');
    ?>
    <h1>GN-System {{ __('dashboard.export.title') }}</h1>
    <p class="meta">
        {{ __('dashboard.export.range') }}：{{ $snapshot['range']['label'] }}（{{ $snapshot['range']['from'] }} {{ __('dashboard.ranges.to') }} {{ $snapshot['range']['to'] }}）；
        {{ __('dashboard.export.generated_at') }}：{{ $snapshot['generated_at'] }}
    </p>
    <table class="metrics">
        <?php foreach (array_chunk(array_keys($metricLabels), 3) as $keys): ?>
            <tr>
                <?php foreach ($keys as $key): ?>
                    <?php $metric = $snapshot['metrics'][$key]; ?>
                    <td>
                        <div class="label">{{ $metricLabels[$key] }}</div>
                        <div class="value">{{ number_format($metric['value']) }}</div>
                        <div>{{ __('dashboard.export.change') }} {{ $metric['change'] === null ? '—' : number_format($metric['change'], 2).'%' }}</div>
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
            <thead><tr><th>{{ __('dashboard.export.item') }}</th><th>{{ __('dashboard.export.value') }}</th></tr></thead>
            <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="2">{{ __('dashboard.export.empty') }}</td></tr>
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
