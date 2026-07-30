<?php

namespace App\Modules\Settlement\Application\Services;

use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Settlement\Application\Contracts\ConfigurationHistoryGateway;
use App\Modules\Settlement\Application\Data\SettlementPeriodData;
use App\Modules\Settlement\Infrastructure\Models\SettlementConfiguration;
use Carbon\CarbonImmutable;
use DomainException;

final class SettlementPeriodCalculator
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly ConfigurationHistoryGateway $configurationHistory,
    ) {}

    public function activeConfiguration(CarbonImmutable $at): SettlementConfiguration
    {
        return SettlementConfiguration::query()
            ->whereDate('effective_from', '<=', $at)
            ->latest('effective_from')
            ->first() ?? SettlementConfiguration::query()->create([
                'boundary_day' => 1,
                'trigger_time' => '09:00:00',
                'timezone' => 'Asia/Shanghai',
                'effective_from' => '1970-01-01',
            ]);
    }

    public function latestClosedPeriod(CarbonImmutable $at): SettlementPeriodData
    {
        $configuration = $this->activeConfiguration($at);
        $local = $at->setTimezone((string) $configuration->timezone);
        $boundary = $local->startOfMonth()
            ->addDays(((int) $configuration->boundary_day) - 1)
            ->setTimeFromTimeString((string) $configuration->trigger_time);
        if ($local->lessThan($boundary)) {
            $boundary = $boundary->subMonthNoOverflow();
        }
        $start = $boundary->subMonthNoOverflow()->startOfDay();
        $end = $boundary->subDay()->endOfDay();

        return new SettlementPeriodData(
            start: $start,
            end: $end,
            boundaryDay: (int) $configuration->boundary_day,
            triggerTime: (string) $configuration->trigger_time,
            timezone: (string) $configuration->timezone,
            configurationId: (int) $configuration->id,
        );
    }

    public function isDue(CarbonImmutable $at): bool
    {
        $configuration = $this->activeConfiguration($at);
        $local = $at->setTimezone((string) $configuration->timezone);

        return $local->day === (int) $configuration->boundary_day
            && $local->format('H:i') === substr((string) $configuration->trigger_time, 0, 5);
    }

    public function nextBoundary(CarbonImmutable $at, int $boundaryDay): CarbonImmutable
    {
        $candidate = $at->startOfMonth()->addDays($boundaryDay - 1)->startOfDay();

        return $candidate->isAfter($at) ? $candidate : $candidate->addMonthNoOverflow();
    }

    public function saveConfiguration(
        int $boundaryDay,
        string $triggerTime,
        int $actorId,
        CarbonImmutable $now,
    ): SettlementConfiguration {
        if ($boundaryDay < 1 || $boundaryDay > 28) {
            throw new DomainException('月结边界日必须在 1 至 28 日之间。');
        }
        if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $triggerTime) !== 1) {
            throw new DomainException('月结触发时间格式无效。');
        }
        $effectiveFrom = $this->nextBoundary($now, $boundaryDay)->toDateString();
        $this->configurationHistory->capture($actorId);

        $configuration = SettlementConfiguration::query()->updateOrCreate(
            ['effective_from' => $effectiveFrom],
            [
                'boundary_day' => $boundaryDay,
                'trigger_time' => $triggerTime.':00',
                'timezone' => 'Asia/Shanghai',
                'created_by' => $actorId,
            ],
        );
        $this->audit->record(
            description: '月结周期配置已保存',
            properties: [
                'boundary_day' => $boundaryDay,
                'trigger_time' => $triggerTime,
                'effective_from' => $effectiveFrom,
            ],
            causerId: $actorId,
            subject: $configuration,
            logName: 'settlement-configuration',
            event: 'updated',
        );

        return $configuration;
    }
}
