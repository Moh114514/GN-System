<?php

namespace App\Modules\Agent\Application\Services;

use App\Modules\Agent\Application\Contracts\ReportAgentReader;
use App\Modules\Agent\Infrastructure\Models\Agent;
use Illuminate\Support\Facades\DB;

final class DatabaseReportAgentReader implements ReportAgentReader
{
    public function globalSearch(string $query, int $limit): array
    {
        $query = trim($query);
        if ($query === '') {
            return ['total' => 0, 'items' => []];
        }

        $agents = Agent::query()->where(fn ($builder) => $builder
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
        return Agent::query()->whereKey(array_values(array_unique($ids)))->pluck('name', 'id')
            ->mapWithKeys(fn ($name, $id): array => [(int) $id => (string) $name])->all();
    }

    public function idsOrderedByName(): array
    {
        return Agent::query()->orderBy('name')->orderBy('id')->pluck('id')
            ->map(fn ($id): int => (int) $id)->all();
    }

    public function activeAgents(): array
    {
        return Agent::query()->where('cooperation_status', 'active')->orderBy('name')->get(['id', 'name'])
            ->map(fn (Agent $agent): array => ['id' => (int) $agent->id, 'name' => (string) $agent->name])
            ->all();
    }

    public function currentGradeDistribution(): array
    {
        return DB::table('agent_grade_assignments as assignment')
            ->join('policy_grades as grade', 'grade.id', '=', 'assignment.policy_grade_id')
            ->join('agents as agent', 'agent.id', '=', 'assignment.agent_id')
            ->where('agent.cooperation_status', 'active')
            ->where('assignment.effective_month', '<=', now('Asia/Shanghai')->startOfMonth()->toDateString())
            ->whereRaw('assignment.effective_month = (
                SELECT MAX(latest.effective_month)
                FROM agent_grade_assignments latest
                WHERE latest.agent_id = assignment.agent_id
                  AND latest.effective_month <= ?
            )', [now('Asia/Shanghai')->startOfMonth()->toDateString()])
            ->select('grade.name as key', DB::raw('COUNT(*)::int as value'))
            ->groupBy('grade.name')
            ->orderBy('grade.name')
            ->get()
            ->map(fn ($row): array => ['key' => (string) $row->key, 'value' => (int) $row->value])
            ->all();
    }
}
