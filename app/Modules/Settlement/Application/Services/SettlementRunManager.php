<?php

namespace App\Modules\Settlement\Application\Services;

use App\Modules\Agent\Application\Contracts\SettlementAgentGateway;
use App\Modules\Settlement\Application\Data\SettlementPeriodData;
use App\Modules\Settlement\Application\Data\SettlementRunStartResult;
use App\Modules\Settlement\Infrastructure\Models\Settlement;
use App\Modules\Settlement\Infrastructure\Models\SettlementRun;
use App\Modules\Settlement\Jobs\GenerateAgentSettlement;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

final readonly class SettlementRunManager
{
    public function __construct(
        private SettlementPeriodCalculator $periods,
        private SettlementAgentGateway $agents,
    ) {}

    public function start(string $source, ?int $actorId, ?CarbonImmutable $at = null): SettlementRun
    {
        return $this->startWithResult($source, $actorId, $at)->run;
    }

    public function startWithResult(string $source, ?int $actorId, ?CarbonImmutable $at = null): SettlementRunStartResult
    {
        $period = $this->periods->latestClosedPeriod($at ?? CarbonImmutable::now());

        return $this->startPeriod($period, $source, $actorId);
    }

    public function startHistorical(string $periodEnd, ?int $actorId, ?CarbonImmutable $at = null): SettlementRun
    {
        return $this->startHistoricalWithResult($periodEnd, $actorId, $at)->run;
    }

    public function startHistoricalWithResult(string $periodEnd, ?int $actorId, ?CarbonImmutable $at = null): SettlementRunStartResult
    {
        $periods = $this->periods->recentClosedPeriods($at ?? CarbonImmutable::now(), 25);
        $selected = collect($periods)->first(
            fn (SettlementPeriodData $period): bool => $period->end->toDateString() === trim($periodEnd),
        );
        $latest = $periods[0] ?? null;
        if (! $selected instanceof SettlementPeriodData || $latest === null || ! $selected->end->isBefore($latest->end)) {
            throw new DomainException(__('settlements.errors.historical_period_invalid'));
        }

        return $this->startPeriod($selected, 'historical', $actorId);
    }

    private function startPeriod(SettlementPeriodData $period, string $source, ?int $actorId): SettlementRunStartResult
    {
        $existing = SettlementRun::query()
            ->whereDate('period_start', $period->start)
            ->whereDate('period_end', $period->end)
            ->first();
        if ($existing !== null) {
            $outcome = match ($existing->status) {
                'queued', 'running' => 'existing_running',
                'completed' => 'existing_completed',
                'partial_failed', 'failed' => 'existing_partial_failed',
                default => 'existing_running',
            };

            return new SettlementRunStartResult($existing, $outcome);
        }
        $eligibleAgentIds = $this->agents->eligibleForPeriod($period->start, $period->end);
        $existingAgentIds = $eligibleAgentIds === []
            ? []
            : Settlement::query()
                ->whereIn('agent_id', $eligibleAgentIds)
                ->whereDate('period_start', $period->start)
                ->whereDate('period_end', $period->end)
                ->pluck('agent_id')
                ->map(static fn ($agentId): int => (int) $agentId)
                ->all();
        $pendingAgentIds = array_values(array_diff($eligibleAgentIds, $existingAgentIds));
        $run = SettlementRun::query()->create([
            'configuration_id' => $period->configurationId,
            'period_start' => $period->start,
            'period_end' => $period->end,
            'trigger_source' => $source,
            'status' => $pendingAgentIds === [] ? 'completed' : 'running',
            'total_agents' => count($eligibleAgentIds),
            'existing_agents' => count($existingAgentIds),
            'existing_agent_ids' => $existingAgentIds,
            'progress_key' => 'settlement:run:'.Str::uuid(),
            'initiated_by' => $actorId,
            'started_at' => now(),
            'completed_at' => $pendingAgentIds === [] ? now() : null,
        ]);
        if ($pendingAgentIds === []) {
            return new SettlementRunStartResult($run->refresh(), 'created_and_completed');
        }
        $jobs = array_map(
            fn (int $agentId): GenerateAgentSettlement => new GenerateAgentSettlement($run->id, $agentId),
            $pendingAgentIds,
        );
        $batch = Bus::batch($jobs)
            ->name("月结 {$period->start->toDateString()} 至 {$period->end->toDateString()}")
            ->allowFailures()
            ->dispatch();
        $run->update(['queue_batch_id' => $batch->id]);
        $run->refresh();
        $outcome = match ($run->status) {
            'completed' => 'created_and_completed',
            'partial_failed', 'failed' => 'created_partial_failed',
            default => 'created_and_dispatched',
        };

        return new SettlementRunStartResult($run, $outcome);
    }

    public function retryFailed(string $runId): SettlementRun
    {
        $run = SettlementRun::query()->findOrFail($runId);
        $agentIds = array_map('intval', array_keys($run->errors ?? []));
        foreach ($agentIds as $agentId) {
            GenerateAgentSettlement::dispatch($run->id, $agentId);
        }
        if ($agentIds !== []) {
            $run->update(['status' => 'running', 'completed_at' => null]);
        }

        return $run->refresh();
    }

    public function startIfDue(?CarbonImmutable $at = null): ?SettlementRun
    {
        $now = $at ?? CarbonImmutable::now();

        return $this->periods->isDue($now) ? $this->start('scheduled', null, $now) : null;
    }
}
