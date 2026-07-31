<?php

namespace App\Modules\Order\Application\Data;

use Carbon\CarbonImmutable;

final readonly class CustomerAppointmentData
{
    public function __construct(
        public int $customerId,
        public int $institutionId,
        public CarbonImmutable $scheduledAt,
        public string $projectName,
        public ?string $translatorName,
        public int $ownerId,
        public ?string $notes,
    ) {}
}
