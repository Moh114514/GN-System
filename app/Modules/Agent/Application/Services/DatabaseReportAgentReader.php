<?php

namespace App\Modules\Agent\Application\Services;

use App\Infrastructure\Time\BusinessClock;
use App\Modules\Agent\Application\Contracts\ReportAgentReader;
use App\Modules\Agent\Infrastructure\Models\Agent;
use App\Modules\Auth\Application\Contracts\AccessContextResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class DatabaseReportAgentReader implements ReportAgentReader
{
    public function __construct(private BusinessClock $clock, private AccessContextResolver $access) {}

    public function globalSearch(string $query, int $limit): array
    {
        $query = trim($query);
        if ($query === '') {
            return ['total' => 0, 'items' => []];
        }

        $agents = $this->scoped(Agent::query())->where(fn ($builder) => $builder
            ->where('name', 'ilike', '%'.$query.'%')
            ->orWhere('code', 'ilike', '%'.strtoupper($query).'%'));
        $total = (clone $agents)->count();
        $items = $agents
            ->orderBy('name')
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get(['id', 'code', 'name', 'cooperation_status'])
            ->map(fn (Agent $agent): array => [
                'id' => (int) $agent->id,
                'code' => (string) $agent->code,
                'name' => (string) $agent->name,
                'status' => (string) $agent->cooperation_status,
            ])
            ->all();

        return ['total' => $total, 'items' => $items];
    }

    public function namesByIds(array $ids): array
    {
        return $this->scoped(Agent::query())->whereKey(array_values(array_unique($ids)))->pluck('name', 'id')
            ->mapWithKeys(fn ($name, $id): array => [(int) $id => (string) $name])->all();
    }

    public function idsOrderedByName(): array
    {
        return $this->scoped(Agent::query())->orderBy('name')->orderBy('id')->pluck('id')
            ->map(fn ($id): int => (int) $id)->all();
    }

    public function activeAgents(): array
    {
        return $this->scoped(Agent::query())->where('cooperation_status', 'active')->orderBy('name')->get(['id', 'name'])
            ->map(fn (Agent $agent): array => ['id' => (int) $agent->id, 'name' => (string) $agent->name])
            ->all();
    }

    public function currentGradeDistribution(): array
    {
        $currentMonth = $this->clock->now()->startOfMonth()->toDateString();

        if ($this->access->current()->isCustomerService()) {
            return [];
        }

        return DB::table('agent_grade_assignments as assignment')
            ->join('policy_grades as grade', 'grade.id', '=', 'assignment.policy_grade_id')
            ->join('agents as agent', 'agent.id', '=', 'assignment.agent_id')
            ->where('agent.cooperation_status', 'active')
            ->when(! $this->access->current()->isSuperAdmin(), fn ($query) => $query->whereIn('agent.id', $this->access->current()->agentIds))
            ->where('assignment.effective_month', '<=', $currentMonth)
            ->whereRaw('assignment.effective_month = (
                SELECT MAX(latest.effective_month)
                FROM agent_grade_assignments latest
                WHERE latest.agent_id = assignment.agent_id
                  AND latest.effective_month <= ?
            )', [$currentMonth])
            ->select('grade.name as key', DB::raw('COUNT(*)::int as value'))
            ->groupBy('grade.name')
            ->orderBy('grade.name')
            ->get()
            ->map(fn ($row): array => ['key' => (string) $row->key, 'value' => (int) $row->value])
            ->all();
    }

    /**
     * @param  Builder<Agent>  $query
     * @return Builder<Agent>
     */
    private function scoped(Builder $query): Builder
    {
        $context = $this->access->current();
        if ($context->isSuperAdmin()) {
            return $query;
        }

        return ! $context->hasEffectiveBusinessScope()
            ? $query->whereRaw('1 = 0')
            : $query->whereKey($context->agentIds);
    }
}
