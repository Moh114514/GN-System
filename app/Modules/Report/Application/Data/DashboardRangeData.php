<?php

namespace App\Modules\Report\Application\Data;

use Carbon\CarbonImmutable;

final readonly class DashboardRangeData
{
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public CarbonImmutable $previousFrom,
        public CarbonImmutable $previousTo,
        public string $label,
    ) {}
}
