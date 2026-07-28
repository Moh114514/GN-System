<?php

namespace App\Modules\Reminder\Application\Data;

use Carbon\CarbonImmutable;

final readonly class CompletedTreatmentData
{
    public function __construct(
        public int $orderId,
        public int $customerId,
        public string $projectName,
        public CarbonImmutable $completedOn,
        public ?int $ownerId,
        public ?int $actorId,
    ) {}
}
