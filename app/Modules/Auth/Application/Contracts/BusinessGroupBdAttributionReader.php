<?php

namespace App\Modules\Auth\Application\Contracts;

use Carbon\CarbonImmutable;

interface BusinessGroupBdAttributionReader
{
    /** @return array<string, mixed>|null */
    public function forGroupOnDate(int $businessGroupId, CarbonImmutable $date): ?array;
}
