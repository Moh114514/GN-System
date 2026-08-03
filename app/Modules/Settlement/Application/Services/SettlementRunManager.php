<?php

namespace App\Modules\Settlement\Application\Services;

use App\Modules\Agent\Application\Contracts\SettlementAgentGateway;
use App\Modules\Settlement\Application\Data\SettlementPeriodData;
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
        $period = $this->periods->latestClosedPeriod($at ?? CarbonImmutable::now());

        return $this->startPeriod($period, $source, $actorId);
    }

    public function startHistorical(string $periodEnd, ?int $actorId, ?CarbonImmutable $at = null): SettlementRun
    {
        $periods = $this->periods->recentClosedPeriods($at ?? CarbonImmutable::now(), 25);
        $selected = collect($periods)->first(
            fn (SettlementPeriodData $period): bool => $period->end->toDateString() === trim($periodEnd),
        );
        $latest = $periods[0] ?? null;
        if (! $selected instanceof SettlementPeriodData || $latest === null || ! $selected->end->isBefore($latest->end)) {
            throw new DomainException('往期月结节点无效，或该节点仍属于最新已关闭周期。');
        }

        return $this->startPeriod($selected, 'historical', $actorId);
    }

    private function startPeriod(SettlementPeriodData $period, string $source, ?int $actorId): SettlementRun
    {
        $existing = SettlementRun::query()
            ->whereDate('period_start', $period->start)
            ->whereDate('period_end', $period->end)
            ->first();
        if ($existing !== null) {
            return $existing;
        }
        $agents = $this->agents->activeForMonth($period->end);
        $run = SettlementRun::query()->create([
            'configuration_id' => $period->configurationId,
            'period_start' => $period->start,
            'period_end' => $period->end,
            'trigger_source' => $source,
            'status' => $agents === [] ? 'completed' : 'running',
            'total_agents' => count($agents),
            'progress_key' => 'settlement:run:'.Str::uuid(),
            'initiated_by' => $actorId,
            'started_at' => now(),
            'completed_at' => $agents === [] ? now() : null,
        ]);
        if ($agents === []) {
            return $run->refresh();
        }
        $jobs = array_map(
            fn ($agent): GenerateAgentSettlement => new GenerateAgentSettlement($run->id, $agent->id),
            $agents,
        );
        $batch = Bus::batch($jobs)
            ->name("月结 {$period->start->toDateString()} 至 {$period->end->toDateString()}")
            ->allowFailures()
            ->dispatch();
        $run->update(['queue_batch_id' => $batch->id]);

        return $run->refresh();
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
