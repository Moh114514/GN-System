<?php

namespace App\Modules\Order\Application\Data;

final readonly class OrderSummaryData
{
    public function __construct(
        public int $id,
        public int $customerId,
        public int $institutionId,
        public int $agentId,
        public string $projectName,
        public int $amountKrw,
        public string $status,
        public ?string $completedOn,
        public ?int $commissionAmountKrw,
        public ?int $commissionRateBps,
    ) {}
}
