<?php

namespace App\Modules\Settlement\Application\Data;

use Carbon\CarbonImmutable;

final readonly class CompletedOrderCommissionCorrectionData
{
    public function __construct(
        public int $orderId,
        public int $agentId,
        public int $institutionId,
        public int $currentOrderAmountKrw,
        public int $newOrderAmountKrw,
        public CarbonImmutable $occurredOn,
        public bool $occurredOnChanged,
        public string $reason,
        public int $actorId,
        public ?string $ipAddress,
    ) {}
}
