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
                'generation_day' => 10,
                'trigger_time' => '09:00:00',
                'timezone' => 'Asia/Shanghai',
                'effective_from' => '1970-01-01',
            ]);
    }

    public function latestClosedPeriod(CarbonImmutable $at): SettlementPeriodData
    {
        $configuration = $this->activeConfiguration($at);
        if ($this->usesNaturalMonth($configuration)) {
            return $this->naturalMonthPeriod($at, $configuration);
        }

        $closingBoundary = $this->boundaryAtOrBefore($at);

        return $this->periodForClosingBoundary($closingBoundary);
    }

    /** @return array<int, SettlementPeriodData> */
    public function recentClosedPeriods(CarbonImmutable $at, int $limit = 13): array
    {
        $periods = [$this->latestClosedPeriod($at)];
        $latest = $periods[0];
        if ($latest->closedAt === null || $limit <= 1) {
            return array_slice($periods, 0, max(1, $limit));
        }

        $candidates = $this->periodCandidates($latest->closedAt, max(1, $limit));
        usort($candidates, static fn (SettlementPeriodData $left, SettlementPeriodData $right): int => ($right->closedAt?->getTimestamp() ?? PHP_INT_MIN)
            <=> ($left->closedAt?->getTimestamp() ?? PHP_INT_MIN));

        foreach ($candidates as $candidate) {
            if (count($periods) >= $limit) {
                break;
            }
            if ($candidate->closedAt === null || ! $candidate->closedAt->isBefore($latest->closedAt)) {
                continue;
            }

            $key = $candidate->start->toDateString().'|'.$candidate->end->toDateString();
            $known = array_map(
                static fn (SettlementPeriodData $period): string => $period->start->toDateString().'|'.$period->end->toDateString(),
                $periods,
            );
            if (! in_array($key, $known, true)) {
                $periods[] = $candidate;
            }
        }

        return array_slice($periods, 0, max(1, $limit));
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
            if ($this->usesNaturalMonth($configuration)) {
                continue;
            }

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
            generationDay: null,
            closedAt: $boundary,
        );
    }

    private function naturalMonthPeriod(CarbonImmutable $at, SettlementConfiguration $configuration): SettlementPeriodData
    {
        $timezone = (string) $configuration->timezone;
        $local = $at->setTimezone($timezone);
        $start = $local->startOfMonth()->subMonthNoOverflow()->startOfMonth();
        $generationAt = $local->startOfMonth()
            ->addDays(((int) $configuration->generation_day) - 1)
            ->setTimeFromTimeString((string) $configuration->trigger_time);

        return new SettlementPeriodData(
            start: $start,
            end: $start->endOfMonth(),
            boundaryDay: (int) $configuration->boundary_day,
            triggerTime: (string) $configuration->trigger_time,
            timezone: $timezone,
            configurationId: (int) $configuration->id,
            generationDay: (int) $configuration->generation_day,
            closedAt: $generationAt,
        );
    }

    /** @return array<int, SettlementPeriodData> */
    private function periodCandidates(CarbonImmutable $upperBound, int $limit): array
    {
        $configurations = SettlementConfiguration::query()->orderBy('effective_from')->get();
        $candidates = [];

        foreach ($configurations as $index => $configuration) {
            $timezone = (string) $configuration->timezone;
            $configurationLocal = $upperBound->setTimezone($timezone);
            $nextConfiguration = $configurations[$index + 1] ?? null;
            $day = $this->usesNaturalMonth($configuration)
                ? (int) $configuration->generation_day
                : (int) $configuration->boundary_day;

            for ($offset = -($limit + 2); $offset <= 1; $offset++) {
                $candidateAt = $configurationLocal->startOfMonth()
                    ->addMonthsNoOverflow($offset)
                    ->addDays($day - 1)
                    ->setTimeFromTimeString((string) $configuration->trigger_time);
                if ($candidateAt->isAfter($upperBound)
                    || $configuration->effective_from->toDateString() > $candidateAt->toDateString()
                    || ($nextConfiguration !== null && $candidateAt->toDateString() >= $nextConfiguration->effective_from->toDateString())) {
                    continue;
                }

                $candidates[] = $this->usesNaturalMonth($configuration)
                    ? $this->naturalMonthPeriod($candidateAt, $configuration)
                    : $this->periodForClosingBoundary(['at' => $candidateAt, 'configuration' => $configuration]);
            }
        }

        return $candidates;
    }

    public function isDue(CarbonImmutable $at): bool
    {
        $configuration = $this->activeConfiguration($at);
        $local = $at->setTimezone((string) $configuration->timezone);
        $day = $this->usesNaturalMonth($configuration)
            ? (int) $configuration->generation_day
            : (int) $configuration->boundary_day;
        $dueAt = $local->startOfMonth()
            ->addDays($day - 1)
            ->setTimeFromTimeString((string) $configuration->trigger_time);

        return ! $local->isBefore($dueAt);
    }

    public function nextGenerationBoundary(CarbonImmutable $at, string $triggerTime, int $generationDay = 10): CarbonImmutable
    {
        $candidate = $at->startOfMonth()
            ->addDays($generationDay - 1)
            ->setTimeFromTimeString($triggerTime);

        return $candidate->isAfter($at)
            ? $candidate
            : $candidate->addMonthNoOverflow();
    }

    public function saveConfiguration(
        string $triggerTime,
        int $actorId,
        CarbonImmutable $now,
    ): SettlementConfiguration {
        if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $triggerTime) !== 1) {
            throw new DomainException(__('settlements.errors.trigger_time_invalid'));
        }
        $activeConfiguration = $this->activeConfiguration($now);
        $generationDay = 10;
        $effectiveFrom = $this->nextGenerationBoundary($now, $triggerTime, $generationDay)->toDateString();
        $this->configurationHistory->capture($actorId);

        $configuration = SettlementConfiguration::query()->updateOrCreate(
            ['effective_from' => $effectiveFrom],
            [
                'boundary_day' => (int) $activeConfiguration->boundary_day,
                'generation_day' => $generationDay,
                'trigger_time' => $triggerTime.':00',
                'timezone' => 'Asia/Shanghai',
                'created_by' => $actorId,
            ],
        );
        $this->audit->record(
            description: '月结周期配置已保存',
            properties: [
                'generation_day' => $generationDay,
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

    private function usesNaturalMonth(SettlementConfiguration $configuration): bool
    {
        return $configuration->generation_day !== null;
    }
}
