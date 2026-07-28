<?php

namespace App\Modules\Settlement\Application\Data;

use Carbon\CarbonImmutable;

final readonly class CompletedOrderCommissionData
{
    public function __construct(
        public int $orderId,
        public int $agentId,
        public int $institutionId,
        public int $orderAmountKrw,
        public CarbonImmutable $completedOn,
        public int $actorId,
        public ?string $ipAddress,
    ) {}
}
