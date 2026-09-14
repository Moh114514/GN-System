<?php

namespace App\Modules\Report\Application\Services;

use App\Modules\Agent\Application\Contracts\ReportAgentReader;
use App\Modules\Auth\Application\Contracts\AccessContextResolver;
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
        private AccessContextResolver $access,
    ) {}

    public function refreshSeconds(): int
    {
        return $this->config->integerParameter('dashboard_refresh_seconds', 300);
    }

    public function snapshot(DashboardRangeData $range, bool $force = false): DashboardSnapshotData
    {
        $key = 'report:dashboard:v6:'.hash('sha256', $range->from->toIso8601String().'|'.$range->to->toIso8601String().'|'.$this->access->current()->fingerprint);
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
        $institutionRevenue = $this->institutionRevenue($current['order']['institution_revenue']);
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
                'institution_revenue' => $institutionRevenue,
            ],
            panels: [
                'promotion_fee' => $current['settlement']['promotion_fee'],
                'monthly_revenue_orders' => $monthlyTrend,
                'settlement_progress' => $current['settlement']['progress'],
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

    /**
     * @param  list<array{institution_id: int, value: int}>  $aggregates
     * @return list<array{id: int, key: string, value: int}>
     */
    private function institutionRevenue(array $aggregates): array
    {
        $context = $this->access->current();
        $visibleIds = $context->isSuperAdmin()
            ? null
            : array_fill_keys($this->orders->visibleInstitutionIds(), true);
        $amounts = [];
        foreach ($aggregates as $aggregate) {
            $id = (int) $aggregate['institution_id'];
            if ($visibleIds !== null && ! isset($visibleIds[$id])) {
                continue;
            }
            $amounts[$id] = ($amounts[$id] ?? 0) + (int) $aggregate['value'];
        }

        $names = [];
        foreach ($this->config->activeInstitutions() as $institution) {
            $id = (int) $institution['id'];
            if ($visibleIds !== null && ! isset($visibleIds[$id])) {
                continue;
            }
            $names[$id] = (string) $institution['name'];
            $amounts[$id] ??= 0;
        }
        $missingIds = array_values(array_diff(array_keys($amounts), array_keys($names)));
        if ($missingIds !== []) {
            $names += $this->config->institutionNamesByIds($missingIds);
        }

        $rows = [];
        foreach ($amounts as $id => $value) {
            $rows[] = [
                'id' => (int) $id,
                'key' => $names[$id] ?? '__dashboard_missing_institution__',
                'value' => (int) $value,
            ];
        }
        usort($rows, static fn (array $left, array $right): int => [$right['value'], mb_strtolower($right['key']), $right['id']]
            <=> [$left['value'], mb_strtolower($left['key']), $left['id']]);

        return $rows;
    }
}
