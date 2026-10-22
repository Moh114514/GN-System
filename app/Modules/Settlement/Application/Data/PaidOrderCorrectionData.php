<?php

namespace App\Modules\Settlement\Application\Data;

use Carbon\CarbonImmutable;

final readonly class PaidOrderCorrectionData
{
    public function __construct(
        public int $orderId,
        public int $agentId,
        public int $orderCommissionId,
        public int $originalSettlementId,
        public int $oldAmountKrw,
        public int $oldCommissionKrw,
        public int $oldRateBps,
        public CarbonImmutable $oldOccurredOn,
        public int $newAmountKrw,
        public int $newCommissionKrw,
        public int $newRateBps,
        public CarbonImmutable $newOccurredOn,
        public string $reason,
        public int $actorId,
        public string $correctionKey,
    ) {}
}
