<?php

namespace App\Modules\Order\Application\Data;

final readonly class OrderSummaryData
{
    public function __construct(
        public int $id,
        public int $customerId,
        public string $sourceType,
        public int $institutionId,
        public ?int $agentId,
        public string $projectName,
        public int $amountKrw,
        public string $status,
        public ?string $occurredOn,
        public ?string $completedOn,
        public string $completionPrecision,
        public ?int $commissionAmountKrw,
        public ?int $commissionRateBps,
    ) {}
}
