<?php

namespace App\Modules\Order\Application\Data;

use Carbon\CarbonImmutable;

final readonly class OrderImportData
{
    public function __construct(
        public int $customerId,
        public int $institutionId,
        public int $agentId,
        public string $projectName,
        public int $amountKrw,
        public ?CarbonImmutable $scheduledAt,
        public ?CarbonImmutable $completedOn,
        public ?string $translatorName,
        public ?string $notes,
        public ?string $importBatchId,
    ) {}
}
