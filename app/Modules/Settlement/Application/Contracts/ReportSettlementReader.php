<?php

namespace App\Modules\Settlement\Application\Contracts;

use Carbon\CarbonImmutable;

interface ReportSettlementReader
{
    /**
     * @param  array<int, string>  $orderMonths order id => YYYY-MM
     * @return array{
     *   promotion_fee: int,
     *   pending_settlement: int,
     *   agent_ranking: array<int, array{agent_id: int, value: int}>,
     *   monthly_promotion: array<int, array{key: string, value: int}>
     * }
     */
    public function dashboard(array $orderMonths, CarbonImmutable $asOf): array;
}
