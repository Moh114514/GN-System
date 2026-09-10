<?php

namespace App\Modules\Order\Application\Data;

final readonly class CompletedOrderItemData
{
    public function __construct(
        public string $projectName,
        public int $amountKrw,
        public ?int $unitPriceKrw = null,
        public string $quantity = '1',
        public ?string $specification = null,
        public ?string $notes = null,
        public ?int $treatmentProjectId = null,
    ) {}
}
