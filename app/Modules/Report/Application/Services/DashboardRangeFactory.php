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
            'today' => [$now->startOfDay(), $now, '今日'],
            'week' => [$now->startOfWeek(), $now, '本周'],
            'month' => [$now->startOfMonth(), $now, '本月'],
            'quarter' => [$now->startOfQuarter(), $now, '本季度'],
            'year' => [$now->startOfYear(), $now, '本年'],
            'custom' => $this->custom($customFrom, $customTo),
            default => throw new DomainException('不支持的看板时间范围。'),
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
            throw new DomainException('自定义区间必须同时填写开始和结束日期。');
        }
        $start = $this->date($from)->startOfDay();
        $end = $this->date($to)->endOfDay();
        if ($start->isAfter($end)) {
            throw new DomainException('自定义区间开始日期不能晚于结束日期。');
        }

        return [$start, $end, $start->toDateString().' 至 '.$end->toDateString()];
    }

    private function date(string $value): CarbonImmutable
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($value), $parts) !== 1
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            throw new DomainException('自定义区间日期格式无效。');
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
