<?php

namespace App\Modules\Order\Application\Data;

use Carbon\CarbonImmutable;

final readonly class SettlementOrderData
{
    public function __construct(
        public int $orderId,
        public int $customerId,
        public int $institutionId,
        public int $agentId,
        public string $projectName,
        public int $amountKrw,
        public CarbonImmutable $completedOn,
    ) {}
}
