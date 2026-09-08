<?php

namespace App\Modules\Report\Application\Data;

final readonly class InstitutionMonthlySalesAgentData
{
    public function __construct(
        public ?int $agentId,
        public int $customerCount,
        public int $orderCount,
        public int $amountKrw,
    ) {}
}
