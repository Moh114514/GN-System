<?php

namespace App\Modules\Order\Application\Contracts;

use App\Modules\Report\Application\Data\ReportPageData;
use App\Modules\Report\Application\Data\ReportQueryData;
use Carbon\CarbonImmutable;

interface ReportOrderReader
{
    public function paginate(ReportQueryData $query, int $perPage, int $page): ReportPageData;

    /** @return array<int, \App\Modules\Report\Application\Data\ReportOrderData> */
    public function rows(ReportQueryData $query): array;

    /** @return array<int, string> order id => YYYY-MM */
    public function completedOrderMonths(CarbonImmutable $from, CarbonImmutable $to): array;

    /**
     * @return array{
     *   completed_amount: int,
     *   repurchase_rate: float,
     *   monthly_consumption: array<int, array{key: string, value: int}>,
     *   institution_revenue: array<int, array{institution_id: int, value: int}>
     * }
     */
    public function dashboard(CarbonImmutable $from, CarbonImmutable $to): array;
}
