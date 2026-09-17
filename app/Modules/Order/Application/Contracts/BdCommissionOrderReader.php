<?php

namespace App\Modules\Order\Application\Contracts;

use App\Modules\Settlement\Application\Data\BdCommissionOrderData;
use Carbon\CarbonImmutable;

interface BdCommissionOrderReader
{
    /** @return list<BdCommissionOrderData> */
    public function completedBetween(CarbonImmutable $periodStart, CarbonImmutable $periodEnd): array;
}
