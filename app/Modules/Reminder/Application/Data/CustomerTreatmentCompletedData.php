<?php

namespace App\Modules\Reminder\Application\Data;

use Carbon\CarbonImmutable;

final readonly class CustomerTreatmentCompletedData
{
    public function __construct(
        public int $customerId,
        public string $projectName,
        public CarbonImmutable $completedAt,
        public ?int $ownerId,
        public ?int $actorId,
    ) {}
}
