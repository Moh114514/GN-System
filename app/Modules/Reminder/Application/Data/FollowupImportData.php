<?php

namespace App\Modules\Reminder\Application\Data;

use Carbon\CarbonImmutable;

final readonly class FollowupImportData
{
    public function __construct(
        public int $customerId,
        public ?int $orderId,
        public string $type,
        public ?CarbonImmutable $followedUpOn,
        public ?string $content,
        public string $importBatchId,
    ) {}
}
