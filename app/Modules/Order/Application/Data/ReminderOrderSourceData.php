<?php

namespace App\Modules\Order\Application\Data;

use Carbon\CarbonImmutable;

final readonly class ReminderOrderSourceData
{
    public function __construct(
        public int $id,
        public int $customerId,
        public string $projectName,
        public CarbonImmutable $completedOn,
        public ?int $ownerId,
    ) {}
}
