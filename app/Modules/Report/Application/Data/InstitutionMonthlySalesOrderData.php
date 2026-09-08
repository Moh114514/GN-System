<?php

namespace App\Modules\Report\Application\Data;

final readonly class InstitutionMonthlySalesOrderData
{
    public function __construct(
        public int $id,
        public string $occurredOn,
        public int $customerId,
        public ?int $agentId,
        public string $projectName,
        public int $amountKrw,
    ) {}
}
