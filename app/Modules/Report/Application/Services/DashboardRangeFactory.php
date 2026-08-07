<?php

namespace App\Modules\Report\Application\Services;

use App\Modules\Report\Application\Data\DashboardRangeData;
use Carbon\CarbonImmutable;
use DomainException;

final class DashboardRangeFactory
{
    public function make(string $preset, ?string $customFrom = null, ?string $customTo = null): DashboardRangeData
    {
        $now = CarbonImmutable::now('Asia/Shanghai');
        [$from, $to, $label] = match ($preset) {
            'today' => [$now->startOfDay(), $now, 'today'],
            'week' => [$now->startOfWeek(), $now, 'week'],
            'month' => [$now->startOfMonth(), $now, 'month'],
            'quarter' => [$now->startOfQuarter(), $now, 'quarter'],
            'year' => [$now->startOfYear(), $now, 'year'],
            'custom' => $this->custom($customFrom, $customTo),
            default => throw new DomainException(__('dashboard.errors.unsupported')),
        };
        $durationMicroseconds = (int) $from->diffInMicroseconds($to);
        $previousTo = $from->subMicrosecond();
        $previousFrom = $previousTo->subMicroseconds($durationMicroseconds);

        return new DashboardRangeData($from, $to, $previousFrom, $previousTo, $label);
    }

    /** @return array{CarbonImmutable, CarbonImmutable, string} */
    private function custom(?string $from, ?string $to): array
    {
        if ($from === null || trim($from) === '' || $to === null || trim($to) === '') {
            throw new DomainException(__('dashboard.errors.custom_required'));
        }
        $start = $this->date($from)->startOfDay();
        $end = $this->date($to)->endOfDay();
        if ($start->isAfter($end)) {
            throw new DomainException(__('dashboard.errors.custom_order'));
        }

        return [$start, $end, 'custom'];
    }

    private function date(string $value): CarbonImmutable
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($value), $parts) !== 1
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            throw new DomainException(__('dashboard.errors.custom_format'));
        }

        return CarbonImmutable::create(
            (int) $parts[1],
            (int) $parts[2],
            (int) $parts[3],
            0,
            0,
            0,
            'Asia/Shanghai',
        );
    }
}
