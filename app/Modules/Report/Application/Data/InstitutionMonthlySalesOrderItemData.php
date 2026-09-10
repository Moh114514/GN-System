<?php

namespace App\Modules\Report\Application\Data;

final readonly class InstitutionMonthlySalesOrderItemData
{
    public function __construct(
        public string $projectName,
        public string $quantity,
        public int $amountKrw,
        public ?string $notes,
    ) {}
}
