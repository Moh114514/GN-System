<?php

namespace App\Modules\Agent\Application\Services;

use App\Modules\Agent\Application\Contracts\SettlementAgentGateway;
use App\Modules\Agent\Application\Data\SettlementAgentData;
use App\Modules\Agent\Infrastructure\Models\Agent;
use App\Modules\Agent\Infrastructure\Models\AgentGradeAssignment;
use App\Modules\Agent\Infrastructure\Models\PolicyGrade;
use Carbon\CarbonImmutable;
use DomainException;

final readonly class DatabaseSettlementAgentGateway implements SettlementAgentGateway
{
    public function __construct(private DatabaseAgentCommissionContextReader $contexts) {}

    public function eligibleForPeriod(CarbonImmutable $periodStart, CarbonImmutable $periodEnd): array
    {
        return Agent::query()
            ->where(function ($query) use ($periodEnd): void {
                $query->whereNull('cooperation_started_on')
                    ->orWhereDate('cooperation_started_on', '<=', $periodEnd);
            })
            ->where(function ($query) use ($periodStart): void {
                $query->whereNull('cooperation_ended_on')
                    ->orWhereDate('cooperation_ended_on', '>=', $periodStart);
            })
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    public function forMonth(int $agentId, CarbonImmutable $month): SettlementAgentData
    {
        return $this->data($agentId, $month);
    }

    public function scheduleGrade(int $agentId, int $gradeId, CarbonImmutable $effectiveMonth, int $actorId, string $reason): void
    {
        $agent = Agent::query()->findOrFail($agentId);
        if ($agent->cooperation_status !== 'active') {
            throw new DomainException(__('agents.validation.active_agent_required_for_grade_schedule'));
        }
        $grade = PolicyGrade::query()->where('is_active', true)->findOrFail($gradeId);
        $current = $this->contexts->forMonth($agentId, $effectiveMonth->subMonthNoOverflow());
        if ((int) $grade->policy_system_id !== $current->policySystemId) {
            throw new DomainException(__('agents.validation.grade_must_match_current_policy'));
        }
        AgentGradeAssignment::query()->updateOrCreate(
            ['agent_id' => $agentId, 'effective_month' => $effectiveMonth->startOfMonth()],
            ['policy_grade_id' => $gradeId, 'approved_by' => $actorId, 'reason' => trim($reason)],
        );
    }

    private function data(int $agentId, CarbonImmutable $month): SettlementAgentData
    {
        $context = $this->contexts->forMonth($agentId, $month);

        return new SettlementAgentData(
            id: $context->agentId,
            code: $context->agentCode,
            name: $context->agentName,
            policySystemId: $context->policySystemId,
            currentGradeId: $context->policyGradeId,
            currentGradeName: $context->policyGradeName,
        );
    }
}
