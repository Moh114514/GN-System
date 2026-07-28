<?php

namespace App\Modules\Order\Application\Data;

use Carbon\CarbonImmutable;

final readonly class ReminderAppointmentData
{
    public function __construct(
        public int $id,
        public int $customerId,
        public CarbonImmutable $scheduledAt,
        public ?int $ownerId,
        public string $status,
    ) {}
}
