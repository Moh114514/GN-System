<?php

namespace App\Modules\Settlement\Application\Contracts;

use Carbon\CarbonImmutable;

interface ReportSettlementReader
{
    /** @return array{commission_amount: int, order_count: int} */
    public function directCommissionDashboard(int $ownerId, CarbonImmutable $from, CarbonImmutable $to): array;

    /**
     * @param  array<int, string>  $orderMonths  order id => YYYY-MM
     * @return array{
     *   promotion_fee: int,
     *   pending_settlement: int,
     *   agent_ranking: array<int, array{agent_id: int, value: int}>,
     *   monthly_promotion: array<int, array{key: string, value: int}>,
     *   progress: array{
     *     percentage: float,
     *     settled_amount: int,
     *     review_amount: int,
     *     pending_amount: int,
     *     expected_amount: int,
     *     period_start: string,
     *     period_end: string
     *   }
     * }
     */
    public function dashboard(array $orderMonths, CarbonImmutable $asOf): array;
}
