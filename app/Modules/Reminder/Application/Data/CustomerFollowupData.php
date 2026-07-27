<?php

namespace App\Modules\Reminder\Application\Data;

use Carbon\CarbonImmutable;

final readonly class CustomerFollowupData
{
    public function __construct(
        public int $customerId,
        public string $type,
        public CarbonImmutable $followedUpOn,
        public string $content,
        public int $ownerId,
    ) {}
}
