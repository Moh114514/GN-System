<?php

namespace App\Modules\Report\Application\Data;

final readonly class InstitutionMonthlySalesAggregateData
{
    public function __construct(
        public int $institutionId,
        public int $customerCount,
        public int $orderCount,
        public int $amountKrw,
    ) {}
}
