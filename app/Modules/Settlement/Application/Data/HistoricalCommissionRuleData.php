<?php

namespace App\Modules\Settlement\Application\Data;

use Carbon\CarbonImmutable;

final readonly class HistoricalCommissionRuleData
{
    public function __construct(
        public int $policyGradeId,
        public int $institutionId,
        public int $rateBps,
        public CarbonImmutable $effectiveMonth,
        public bool $isActive,
        public string $importBatchId,
        public string $reason,
        public int $actorId,
        public ?string $ipAddress,
    ) {}
}
