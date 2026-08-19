<?php

namespace App\Modules\Agent\Application\Services;

use App\Infrastructure\Time\BusinessClock;
use App\Modules\Agent\Infrastructure\Models\Agent;
use App\Modules\Agent\Infrastructure\Models\AgentGradeAssignment;
use App\Modules\Agent\Infrastructure\Models\AgentTypeCode;
use App\Modules\Agent\Infrastructure\Models\PolicyGrade;
use App\Modules\Agent\Infrastructure\Models\PolicySystem;
use App\Modules\Customer\Application\Contracts\AgentCustomerPortfolioReader;
use App\Modules\Order\Application\Contracts\DailyOrderGateway;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final readonly class AgentDirectory
{
    public function __construct(
        private AgentCustomerPortfolioReader $customers,
        private DailyOrderGateway $orders,
        private BusinessClock $clock,
    ) {}

    /** @return LengthAwarePaginator<int, array<string, mixed>> */
    public function paginate(
        string $search,
        string $status,
        ?int $typeCodeId = null,
        ?int $policySystemId = null,
        ?int $policyGradeId = null,
        int $perPage = 20,
    ): LengthAwarePaginator {
        $query = Agent::query();
        $search = trim($search);
        if ($search !== '') {
            $query->where(fn ($builder) => $builder
                ->where('name', 'ilike', '%'.$search.'%')
                ->orWhere('code', 'ilike', '%'.strtoupper($search).'%'));
        }
        if ($status !== '') {
            $query->where('cooperation_status', $status);
        }
        if ($typeCodeId !== null) {
            $query->where('agent_type_code_id', $typeCodeId);
        }
        if ($policySystemId !== null || $policyGradeId !== null) {
            $month = $this->currentMonth();
            $currentAssignments = DB::table('agent_grade_assignments as current_assignment')
                ->join('policy_grades as current_grade', 'current_grade.id', '=', 'current_assignment.policy_grade_id')
                ->select('current_assignment.agent_id')
                ->whereDate('current_assignment.effective_month', '<=', $month)
                ->whereNotExists(function ($newerAssignment) use ($month): void {
                    $newerAssignment
                        ->selectRaw('1')
                        ->from('agent_grade_assignments as newer_assignment')
                        ->whereColumn('newer_assignment.agent_id', 'current_assignment.agent_id')
                        ->whereColumn('newer_assignment.effective_month', '>', 'current_assignment.effective_month')
                        ->whereDate('newer_assignment.effective_month', '<=', $month);
                });
            if ($policySystemId !== null) {
                $currentAssignments->where('current_grade.policy_system_id', $policySystemId);
            }
            if ($policyGradeId !== null) {
                $currentAssignments->where('current_assignment.policy_grade_id', $policyGradeId);
            }
            $query->whereIn('agents.id', $currentAssignments);
        }
        $page = $query->latest('created_at')->paginate($perPage);
        $types = AgentTypeCode::query()->whereKey($page->getCollection()->pluck('agent_type_code_id'))->pluck('name', 'id');
        $assignments = $this->currentAssignments($page->getCollection()->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $grades = PolicyGrade::query()->whereKey(array_column($assignments, 'policy_grade_id'))->get()->keyBy('id');
        $systems = PolicySystem::query()->whereKey($grades->pluck('policy_system_id'))->pluck('name', 'id');
        $items = $page->getCollection()->map(function (Agent $agent) use ($types, $assignments, $grades, $systems): array {
            $assignment = $assignments[(int) $agent->id] ?? null;
            $grade = $assignment === null ? null : $grades->get($assignment['policy_grade_id']);
            $gradeName = $grade instanceof PolicyGrade ? (string) $grade->name : __('agents.fallback.unset');
            $policyName = $grade instanceof PolicyGrade
                ? (string) ($systems[$grade->policy_system_id] ?? __('agents.fallback.unknown_policy'))
                : __('agents.fallback.unset');

            return [
                'id' => (int) $agent->id,
                'code' => (string) $agent->code,
                'name' => (string) $agent->name,
                'type' => (string) ($types[$agent->agent_type_code_id] ?? __('agents.fallback.unknown_type')),
                'status' => (string) $agent->cooperation_status,
                'policy' => $policyName,
                'grade' => $gradeName,
                'created_at' => $agent->created_at?->format('Y-m-d H:i'),
            ];
        });

        // PHPStan treats the inferred array shape as invariant even though it matches the declared shape.
        // @phpstan-ignore return.type
        return new LengthAwarePaginator($items, $page->total(), $page->perPage(), $page->currentPage(), [
            'path' => request()->url(),
            'query' => request()->query(),
        ]);
    }

    /** @return array<string, mixed> */
    public function options(): array
    {
        return [
            'types' => AgentTypeCode::query()->where('is_active', true)->orderBy('code')->get()->toArray(),
            'systems' => PolicySystem::query()->where('is_active', true)->orderBy('name')->get()->toArray(),
            'grades' => PolicyGrade::query()->where('is_active', true)->orderBy('policy_system_id')->orderBy('sort_order')->get()->toArray(),
        ];
    }

    /** @return array<string, mixed> */
    public function filterOptions(): array
    {
        return [
            'types' => AgentTypeCode::query()->orderBy('code')->get(['id', 'code', 'name'])->toArray(),
            'systems' => PolicySystem::query()->orderBy('name')->get(['id', 'name'])->toArray(),
            'grades' => PolicyGrade::query()
                ->orderBy('policy_system_id')
                ->orderBy('sort_order')
                ->get(['id', 'policy_system_id', 'name'])
                ->toArray(),
        ];
    }

    /** @return array<string, mixed> */
    public function profile(int $agentId): array
    {
        $agent = Agent::query()->findOrFail($agentId);
        $assignment = AgentGradeAssignment::query()
            ->where('agent_id', $agentId)
            ->whereDate('effective_month', '<=', $this->currentMonth())
            ->latest('effective_month')
            ->first();
        $grade = $assignment === null ? null : PolicyGrade::query()->find($assignment->policy_grade_id);
        $system = $grade === null ? null : PolicySystem::query()->find($grade->policy_system_id);
        $type = AgentTypeCode::query()->findOrFail($agent->agent_type_code_id);

        return [
            'id' => (int) $agent->id,
            'code' => (string) $agent->code,
            'name' => (string) $agent->name,
            'agent_type_code_id' => (int) $agent->agent_type_code_id,
            'type' => $type->name,
            'business_role' => $agent->business_role,
            'contact_name' => $agent->contact_name,
            'contact_value' => $agent->contact_value,
            'cooperation_started_on' => $agent->cooperation_started_on?->format('Y-m-d'),
            'cooperation_ended_on' => $agent->cooperation_ended_on?->format('Y-m-d'),
            'cooperation_status' => (string) $agent->cooperation_status,
            'notes' => $agent->notes,
            'policy_system' => $system?->name,
            'policy_grade' => $grade?->name,
            'policy_grade_id' => $grade?->id,
            'grade_effective_month' => $assignment?->effective_month?->format('Y-m-d'),
            'grade_history' => $this->gradeHistory($agentId),
            'customers' => $this->customers->customersForAgent($agentId),
            'orders' => $this->orders->forAgent($agentId),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function gradeHistory(int $agentId): array
    {
        $month = $this->currentMonth();
        $assignments = AgentGradeAssignment::query()
            ->where('agent_id', $agentId)
            ->with(['policyGrade.policySystem'])
            ->orderByDesc('effective_month')
            ->get();
        $currentId = $assignments
            ->filter(fn (AgentGradeAssignment $assignment): bool => $assignment->effective_month->lte($month))
            ->sortByDesc('effective_month')
            ->first()?->id;

        return $assignments->map(function (AgentGradeAssignment $assignment) use ($month, $currentId): array {
            $grade = $assignment->policyGrade;
            $system = $grade?->policySystem;

            return [
                'effective_month' => $assignment->effective_month->format('Y-m-d'),
                'policy_system' => $system?->name,
                'policy_grade' => $grade?->name,
                'reason' => $assignment->reason,
                'source' => $assignment->import_batch_id === null ? 'manual' : 'import',
                'status' => $assignment->id === $currentId
                    ? 'current'
                    : ($assignment->effective_month->gt($month) ? 'pending' : 'historical'),
            ];
        })->values()->all();
    }

    /** @param array<int, int> $agentIds
     * @return array<int, array{policy_grade_id: int, effective_month: string}>
     */
    private function currentAssignments(array $agentIds): array
    {
        if ($agentIds === []) {
            return [];
        }
        $rows = DB::table('agent_grade_assignments')
            ->whereIn('agent_id', $agentIds)
            ->whereDate('effective_month', '<=', $this->currentMonth())
            ->orderByDesc('effective_month')
            ->get();
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row->agent_id] ??= [
                'policy_grade_id' => (int) $row->policy_grade_id,
                'effective_month' => (string) $row->effective_month,
            ];
        }

        return $result;
    }

    private function currentMonth(): CarbonImmutable
    {
        return $this->clock->now()->startOfMonth();
    }
}
