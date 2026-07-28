<?php

namespace App\Modules\Order\Application\Contracts;

use App\Modules\Order\Application\Data\SettlementOrderData;
use Carbon\CarbonImmutable;

interface SettlementOrderReader
{
    /** @return array<int, SettlementOrderData> */
    public function completedForAgent(int $agentId, CarbonImmutable $periodStart, CarbonImmutable $periodEnd): array;
}
