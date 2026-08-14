<?php

namespace App\Modules\Settlement\Application\Data;

use Carbon\CarbonImmutable;

final readonly class SettlementPeriodData
{
    public function __construct(
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public int $boundaryDay,
        public string $triggerTime,
        public string $timezone,
        public ?int $configurationId,
        public ?int $generationDay = null,
        public ?CarbonImmutable $closedAt = null,
    ) {}
}
