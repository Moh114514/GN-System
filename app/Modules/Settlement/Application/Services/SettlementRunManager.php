<?php

namespace App\Modules\Settlement\Application\Services;

use App\Modules\Agent\Application\Contracts\SettlementAgentGateway;
use App\Modules\Settlement\Application\Data\SettlementPeriodData;
use App\Modules\Settlement\Application\Data\SettlementRunStartResult;
use App\Modules\Settlement\Infrastructure\Models\Settlement;
use App\Modules\Settlement\Infrastructure\Models\SettlementRun;
use App\Modules\Settlement\Infrastructure\Models\SettlementRunMember;
use App\Modules\Settlement\Jobs\GenerateAgentSettlement;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class SettlementRunManager
{
    public function __construct(
        private SettlementPeriodCalculator $periods,
        private SettlementAgentGateway $agents,
        private SettlementRunSummaryUpdater $summary,
    ) {}

    public function start(string $source, ?int $actorId, ?CarbonImmutable $at = null): SettlementRun
    {
        return $this->startWithResult($source, $actorId, $at)->run;
    }

    public function startWithResult(string $source, ?int $actorId, ?CarbonImmutable $at = null): SettlementRunStartResult
    {
        return $this->startPeriod($this->periods->latestClosedPeriod($at ?? CarbonImmutable::now()), $source, $actorId);
    }

    public function startHistorical(string $periodEnd, ?int $actorId, ?CarbonImmutable $at = null): SettlementRun
    {
        return $this->startHistoricalWithResult($periodEnd, $actorId, $at)->run;
    }

    public function startHistoricalWithResult(string $periodEnd, ?int $actorId, ?CarbonImmutable $at = null): SettlementRunStartResult
    {
        $periods = $this->periods->recentClosedPeriods($at ?? CarbonImmutable::now(), 25);
        $selected = collect($periods)->first(fn (SettlementPeriodData $period): bool => $period->end->toDateString() === trim($periodEnd));
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
            return new SettlementRunStartResult($existing, match ($existing->status) {
                'queued', 'running' => 'existing_running',
                'completed' => 'existing_completed',
                'partial_failed', 'failed' => 'existing_partial_failed',
                default => 'existing_running',
            });
        }

        $eligibleAgentIds = $this->agents->eligibleForPeriod($period->start, $period->end);
        $run = DB::transaction(function () use ($period, $source, $actorId, $eligibleAgentIds): SettlementRun {
            $run = SettlementRun::query()->create([
                'configuration_id' => $period->configurationId,
                'period_start' => $period->start,
                'period_end' => $period->end,
                'trigger_source' => $source,
                'status' => 'queued',
                'total_agents' => count($eligibleAgentIds),
                'progress_key' => 'settlement:run:'.Str::uuid(),
                'initiated_by' => $actorId,
                'started_at' => now(),
            ]);

            foreach ($eligibleAgentIds as $agentId) {
                $settlement = Settlement::query()
                    ->where('agent_id', $agentId)
                    ->whereDate('period_start', $period->start)
                    ->whereDate('period_end', $period->end)
                    ->first();
                SettlementRunMember::query()->create([
                    'settlement_run_id' => $run->id,
                    'agent_id' => $agentId,
                    'settlement_id' => $settlement?->id,
                    'outcome' => $settlement === null ? 'pending' : 'existing',
                    'processed_at' => $settlement === null ? null : now(),
                ]);
            }

            return $run;
        }, 3);

        $this->summary->update($run);
        $pendingMembers = $run->members()->where('outcome', 'pending')->get();
        if ($pendingMembers->isEmpty()) {
            return new SettlementRunStartResult($run->refresh(), 'created_and_completed');
        }

        $jobs = $pendingMembers->map(fn (SettlementRunMember $member): GenerateAgentSettlement => new GenerateAgentSettlement($member->id, (int) $member->agent_id))->all();
        $batch = Bus::batch($jobs)
            ->name("Settlement {$period->start->toDateString()} to {$period->end->toDateString()}")
            ->allowFailures()
            ->dispatch();
        $run->update(['queue_batch_id' => $batch->id]);
        $run = $this->summary->update($run);

        return new SettlementRunStartResult($run, match ($run->status) {
            'completed' => 'created_and_completed',
            'partial_failed', 'failed' => 'created_partial_failed',
            default => 'created_and_dispatched',
        });
    }

    public function retryFailed(string $runId): SettlementRun
    {
        $run = SettlementRun::query()->findOrFail($runId);
        $members = $run->members()->where('outcome', 'failed')->get();
        foreach ($members as $member) {
            $member->update([
                'outcome' => 'pending',
                'error_message_key' => null,
                'error_parameters' => null,
                'processed_at' => null,
            ]);
            GenerateAgentSettlement::dispatch($member->id, (int) $member->agent_id);
        }

        return $this->summary->update($run);
    }

    /** Scheduler compensation: create the latest closed period whenever it is missing. */
    public function startIfDue(?CarbonImmutable $at = null): ?SettlementRun
    {
        $now = $at ?? CarbonImmutable::now();
        $period = $this->periods->latestClosedPeriod($now);
        $exists = SettlementRun::query()
            ->whereDate('period_start', $period->start)
            ->whereDate('period_end', $period->end)
            ->exists();

        return $exists ? null : $this->start('scheduled', null, $now);
    }
}
