<?php

namespace App\Modules\Report\Application\Services;

use App\Modules\Agent\Application\Contracts\ReportAgentReader;
use App\Modules\Auth\Application\Contracts\ReportUserReader;
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
        private ReportUserReader $users,
    ) {}

    public function refreshSeconds(): int
    {
        return $this->config->integerParameter('dashboard_refresh_seconds', 300);
    }

    public function snapshot(DashboardRangeData $range, bool $force = false): DashboardSnapshotData
    {
        $key = 'report:dashboard:v3:'.hash('sha256', $range->from->toIso8601String().'|'.$range->to->toIso8601String());
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
            ...array_column(
                array_filter(
                    $current['customer']['recent_customers'],
                    fn (array $row): bool => $row['source_type'] === 'agent',
                ),
                'source_id',
            ),
        ];
        $agentNames = $this->agents->namesByIds($agentIds);
        $institutionNames = $this->config->institutionNamesByIds(array_column($current['order']['institution_revenue'], 'institution_id'));
        $taskCustomerNames = $this->customers->namesByIds(array_column($current['reminder']['today_tasks'], 'customer_id'));
        $ownerNames = $this->users->namesByIds(array_column($current['customer']['recent_customers'], 'owner_id'));
        $monthlyOrders = [];
        foreach ($current['order']['monthly_orders'] as $monthlyOrder) {
            $monthlyOrders[(string) $monthlyOrder['key']] = (int) $monthlyOrder['value'];
        }
        $monthlyTrend = array_map(fn (array $row): array => [
            'key' => $row['key'],
            'value' => $row['value'],
            'orders' => $monthlyOrders[$row['key']] ?? 0,
        ], $current['order']['monthly_consumption']);

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
                    'id' => $row['agent_id'],
                    'key' => $agentNames[$row['agent_id']] ?? '__dashboard_missing_agent__',
                    'value' => $row['value'],
                ], $current['settlement']['agent_ranking']),
                'monthly_promotion' => $current['settlement']['monthly_promotion'],
                'grade_distribution' => $this->agents->currentGradeDistribution(),
                'source_distribution' => array_map(fn (array $row): array => [
                    'key' => $agentNames[$row['source_id']] ?? '__dashboard_missing_agent__',
                    'value' => $row['value'],
                ], $current['customer']['source_distribution']),
                'monthly_consumption' => $current['order']['monthly_consumption'],
                'repurchase_rate' => [['key' => '__dashboard_repurchase_rate__', 'value' => $current['order']['repurchase_rate']]],
                'followup_completion_rate' => [['key' => '__dashboard_followup_completion_rate__', 'value' => $current['reminder']['followup_completion_rate']]],
                'institution_revenue' => array_map(fn (array $row): array => [
                    'key' => $institutionNames[$row['institution_id']] ?? '__dashboard_missing_institution__',
                    'value' => $row['value'],
                ], $current['order']['institution_revenue']),
            ],
            panels: [
                'promotion_fee' => $current['settlement']['promotion_fee'],
                'pending_reminders' => $current['reminder']['pending_reminders'],
                'monthly_revenue_orders' => $monthlyTrend,
                'lifecycle' => $this->lifecycle($current),
                'today_tasks' => array_map(fn (array $task): array => [
                    ...$task,
                    'customer_name' => $taskCustomerNames[$task['customer_id']] ?? '__dashboard_missing_customer__',
                ], $current['reminder']['today_tasks']),
                'recent_customers' => array_map(fn (array $customer): array => [
                    ...$customer,
                    'source_name' => $agentNames[$customer['source_id']] ?? '__dashboard_missing_agent__',
                    'owner_name' => $ownerNames[$customer['owner_id']] ?? '__dashboard_unassigned__',
                ], $current['customer']['recent_customers']),
                'settlement_progress' => $current['settlement']['progress'],
            ],
            generatedAt: now('Asia/Shanghai')->toIso8601String(),
        );
    }

    /**
     * @param  array<string, array<string, mixed>>  $current
     * @return array<int, array{key: string, value: int, percentage: float}>
     */
    private function lifecycle(array $current): array
    {
        $total = (int) $current['customer']['total_customers'];
        $rows = [
            'booked' => (int) ($current['customer']['status_counts']['booked'] ?? 0),
            'arrived' => (int) ($current['customer']['status_counts']['arrived'] ?? 0),
            'treatment_completed' => (int) ($current['customer']['status_counts']['treatment_completed'] ?? 0),
        ];

        return array_map(fn (string $key, int $value): array => [
            'key' => $key,
            'value' => $value,
            'percentage' => $total === 0 ? 0.0 : round($value / $total * 100, 1),
        ], array_keys($rows), array_values($rows));
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
