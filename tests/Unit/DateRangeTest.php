<?php

namespace Tests\Unit;

use App\Support\DateRange;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class DateRangeTest extends TestCase
{
    public function test_month_preset_uses_half_open_boundaries(): void
    {
        $range = DateRange::preset('month', CarbonImmutable::parse('2026-08-18 12:00:00', 'Asia/Shanghai'));

        $this->assertSame('2026-08-01 00:00:00', $range->startAt?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-01 00:00:00', $range->endExclusive?->format('Y-m-d H:i:s'));
    }

    public function test_explicit_end_date_is_exclusive_on_the_following_day(): void
    {
        $range = DateRange::fromDates('2026-08-01', '2026-08-31');

        $this->assertSame('2026-08-01 00:00:00', $range->startAt?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-01 00:00:00', $range->endExclusive?->format('Y-m-d H:i:s'));
    }
}
