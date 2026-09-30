<?php

namespace App\Modules\Settlement\Application\Data;

use Carbon\CarbonImmutable;

final readonly class DirectOrderCommissionData
{
    /** @param array<string, mixed>|null $directChannel */
    public function __construct(
        public int $orderId,
        public int $ownerId,
        public int $orderAmountKrw,
        public CarbonImmutable $completedAt,
        public ?array $directChannel,
        public int $actorId,
        public ?string $ipAddress,
    ) {}
}
