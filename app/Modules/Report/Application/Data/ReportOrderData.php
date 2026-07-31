<?php

namespace App\Modules\Report\Application\Data;

final readonly class ReportOrderData
{
    public function __construct(
        public int $id,
        public int $customerId,
        public ?int $agentId,
        public int $institutionId,
        public string $projectName,
        public ?string $translatorName,
        public int $amountKrw,
        public string $completedAt,
        public string $completionPrecision,
    ) {}
}
