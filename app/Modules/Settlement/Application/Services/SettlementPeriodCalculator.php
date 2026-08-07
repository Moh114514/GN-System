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
        $closingBoundary = $this->boundaryAtOrBefore($at);

        return $this->periodForClosingBoundary($closingBoundary);
    }

    /** @return array<int, SettlementPeriodData> */
    public function recentClosedPeriods(CarbonImmutable $at, int $limit = 13): array
    {
        $periods = [];
        $closingBoundary = $this->boundaryAtOrBefore($at);
        for ($index = 0; $index < max(1, $limit); $index++) {
            $periods[] = $this->periodForClosingBoundary($closingBoundary);
            $closingBoundary = $this->boundaryAtOrBefore(($closingBoundary['at'])->subMicrosecond());
        }

        return $periods;
    }

    /**
     * @return array{at: CarbonImmutable, configuration: SettlementConfiguration}
     */
    private function boundaryAtOrBefore(CarbonImmutable $at): array
    {
        $this->activeConfiguration($at);
        $configurations = SettlementConfiguration::query()->orderBy('effective_from')->get();
        $candidates = [];

        foreach ($configurations as $index => $configuration) {
            $timezone = (string) $configuration->timezone;
            $local = $at->setTimezone($timezone);
            $nextConfiguration = $configurations[$index + 1] ?? null;
            for ($offset = -2; $offset <= 1; $offset++) {
                $candidate = $local->startOfMonth()
                    ->addMonthsNoOverflow($offset)
                    ->addDays(((int) $configuration->boundary_day) - 1)
                    ->setTimeFromTimeString((string) $configuration->trigger_time);
                if ($candidate->isAfter($at)
                    || $configuration->effective_from->toDateString() > $candidate->toDateString()
                    || ($nextConfiguration !== null && $candidate->toDateString() >= $nextConfiguration->effective_from->toDateString())) {
                    continue;
                }

                $candidates[] = ['at' => $candidate, 'configuration' => $configuration];
            }
        }

        usort($candidates, function (array $left, array $right): int {
            $atComparison = $right['at']->getTimestamp() <=> $left['at']->getTimestamp();
            if ($atComparison !== 0) {
                return $atComparison;
            }

            return $right['configuration']->effective_from->getTimestamp()
                <=> $left['configuration']->effective_from->getTimestamp();
        });

        if ($candidates === []) {
            throw new DomainException(__('settlements.errors.period_boundary_rebuild_failed'));
        }

        return $candidates[0];
    }

    /**
     * @param  array{at: CarbonImmutable, configuration: SettlementConfiguration}  $closingBoundary
     */
    private function periodForClosingBoundary(array $closingBoundary): SettlementPeriodData
    {
        $previousBoundary = $this->boundaryAtOrBefore(($closingBoundary['at'])->subMicrosecond());
        $configuration = $closingBoundary['configuration'];
        $timezone = (string) $configuration->timezone;
        $boundary = $closingBoundary['at']->setTimezone($timezone);
        $start = $previousBoundary['at']->setTimezone($timezone)->startOfDay();
        $end = $boundary->subDay()->endOfDay();

        return new SettlementPeriodData(
            start: $start,
            end: $end,
            boundaryDay: (int) $configuration->boundary_day,
            triggerTime: (string) $configuration->trigger_time,
            timezone: $timezone,
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
            throw new DomainException(__('settlements.errors.boundary_day_invalid'));
        }
        if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $triggerTime) !== 1) {
            throw new DomainException(__('settlements.errors.trigger_time_invalid'));
        }
        $this->activeConfiguration($now);
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
