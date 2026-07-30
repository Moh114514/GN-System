<?php

namespace App\Modules\Report\Application\Services;

use App\Modules\Agent\Application\Contracts\ReportAgentReader;
use App\Modules\Config\Application\Contracts\ReportConfigReader;
use App\Modules\Customer\Application\Contracts\ReportCustomerReader;
use App\Modules\Order\Application\Contracts\ReportOrderReader;
use App\Modules\Reminder\Application\Contracts\ReportReminderReader;
use App\Modules\Report\Application\Data\DashboardRangeData;
use App\Modules\Report\Application\Data\DashboardSnapshotData;
use App\Modules\Settlement\Application\Contracts\ReportSettlementReader;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

final readonly class DashboardService
{
    public function __construct(
        private ReportOrderReader $orders,
        private ReportCustomerReader $customers,
        private ReportAgentReader $agents,
        private ReportConfigReader $config,
        private ReportSettlementReader $settlements,
        private ReportReminderReader $reminders,
    ) {}

    public function refreshSeconds(): int
    {
        return $this->config->integerParameter('dashboard_refresh_seconds', 300);
    }

    public function snapshot(DashboardRangeData $range, bool $force = false): DashboardSnapshotData
    {
        $key = 'report:dashboard:'.hash('sha256', $range->from->toIso8601String().'|'.$range->to->toIso8601String());
        if ($force) {
            try {
                Cache::forget($key);
            } catch (Throwable $exception) {
                Log::warning('Dashboard cache invalidation failed; continuing with database aggregation.', [
                    'exception' => $exception->getMessage(),
                ]);
            }
        }
        try {
            $cached = Cache::get($key);
            if ($cached instanceof DashboardSnapshotData) {
                return $cached;
            }
        } catch (Throwable $exception) {
            Log::warning('Dashboard cache read failed; using database aggregation.', [
                'exception' => $exception->getMessage(),
            ]);
        }

        $snapshot = $this->aggregate($range);
        try {
            Cache::put($key, $snapshot, now()->addMinutes(5));
        } catch (Throwable $exception) {
            Log::warning('Dashboard cache write failed; database aggregation remains valid.', [
                'exception' => $exception->getMessage(),
            ]);
        }

        return $snapshot;
    }

    private function aggregate(DashboardRangeData $range): DashboardSnapshotData
    {
        $current = $this->period($range->from, $range->to);
        $previous = $this->period($range->previousFrom, $range->previousTo);
        $agentIds = [
            ...array_column($current['settlement']['agent_ranking'], 'agent_id'),
            ...array_column(
                array_filter(
                    $current['customer']['source_distribution'],
                    fn (array $row): bool => $row['source_type'] === 'agent',
                ),
                'source_id',
            ),
        ];
        $agentNames = $this->agents->namesByIds($agentIds);
        $institutionNames = $this->config->institutionNamesByIds(array_column($current['order']['institution_revenue'], 'institution_id'));

        return new DashboardSnapshotData(
            range: $range,
            metrics: [
                'new_customers' => $this->metric($current['customer']['new_customers'], $previous['customer']['new_customers']),
                'completed_amount' => $this->metric($current['order']['completed_amount'], $previous['order']['completed_amount']),
                'revenue' => $this->metric($current['order']['completed_amount'], $previous['order']['completed_amount']),
                'active_customers' => $this->metric($current['customer']['active_customers'], $previous['customer']['active_customers']),
                'overdue_customers' => $this->metric($current['reminder']['overdue_customers'], $previous['reminder']['overdue_customers']),
                'pending_settlement' => $this->metric($current['settlement']['pending_settlement'], $previous['settlement']['pending_settlement']),
            ],
            charts: [
                'agent_promotion_ranking' => array_map(fn (array $row): array => [
                    'key' => $agentNames[$row['agent_id']] ?? '未知代理商',
                    'value' => $row['value'],
                ], $current['settlement']['agent_ranking']),
                'monthly_promotion' => $current['settlement']['monthly_promotion'],
                'grade_distribution' => $this->agents->currentGradeDistribution(),
                'source_distribution' => array_map(fn (array $row): array => [
                    'key' => $row['source_type'] === 'agent'
                        ? ($agentNames[$row['source_id']] ?? '未知代理商')
                        : $row['key'],
                    'value' => $row['value'],
                ], $current['customer']['source_distribution']),
                'monthly_consumption' => $current['order']['monthly_consumption'],
                'repurchase_rate' => [['key' => '复购率', 'value' => $current['order']['repurchase_rate']]],
                'followup_completion_rate' => [['key' => '跟进完成率', 'value' => $current['reminder']['followup_completion_rate']]],
                'institution_revenue' => array_map(fn (array $row): array => [
                    'key' => $institutionNames[$row['institution_id']] ?? '未知机构',
                    'value' => $row['value'],
                ], $current['order']['institution_revenue']),
            ],
            generatedAt: now('Asia/Shanghai')->toIso8601String(),
        );
    }

    /** @return array<string, array<string, mixed>> */
    private function period(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $orderMonths = $this->orders->completedOrderMonths($from, $to);

        return [
            'customer' => $this->customers->dashboard($from, $to),
            'order' => $this->orders->dashboard($from, $to),
            'settlement' => $this->settlements->dashboard($orderMonths, $to),
            'reminder' => $this->reminders->dashboard($from, $to),
        ];
    }

    /** @return array{value: int|float, previous: int|float, change: float|null} */
    private function metric(int|float $value, int|float $previous): array
    {
        return [
            'value' => $value,
            'previous' => $previous,
            'change' => $previous == 0 ? null : round(($value - $previous) / abs($previous) * 100, 2),
        ];
    }
}
