<?php

namespace App\Modules\Settlement\Application\Data;

use Carbon\CarbonImmutable;

final readonly class BdCommissionOrderData
{
    /** @param array<string, mixed>|null $attributionSnapshot */
    public function __construct(
        public int $orderId,
        public int $amountKrw,
        public CarbonImmutable $occurredOn,
        public ?array $attributionSnapshot,
        public bool $active = true,
    ) {}
}
