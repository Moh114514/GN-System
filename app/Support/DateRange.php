<?php

namespace App\Support;

use Carbon\CarbonImmutable;

final readonly class DateRange
{
    public function __construct(
        public ?CarbonImmutable $startAt,
        public ?CarbonImmutable $endExclusive,
    ) {}

    public static function fromDates(?string $from, ?string $to, string $timezone = 'Asia/Shanghai'): self
    {
        $start = $from === null || trim($from) === ''
            ? null
            : CarbonImmutable::createFromFormat('!Y-m-d', trim($from), $timezone)->startOfDay();
        $end = $to === null || trim($to) === ''
            ? null
            : CarbonImmutable::createFromFormat('!Y-m-d', trim($to), $timezone)->addDay()->startOfDay();

        return new self($start, $end);
    }

    public static function preset(string $preset, ?CarbonImmutable $now = null, string $timezone = 'Asia/Shanghai'): self
    {
        $today = ($now ?? CarbonImmutable::now($timezone))->setTimezone($timezone)->startOfDay();

        return match ($preset) {
            'today' => new self($today, $today->addDay()),
            'month' => new self($today->startOfMonth(), $today->startOfMonth()->addMonthNoOverflow()),
            'year' => new self($today->startOfYear(), $today->startOfYear()->addYear()),
            default => new self(null, null),
        };
    }
}
