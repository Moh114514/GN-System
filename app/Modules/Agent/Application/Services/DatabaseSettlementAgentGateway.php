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

    public function recommendation(int $agentId, CarbonImmutable $month, int $commissionKrw): SettlementAgentData
    {
        $current = $this->contexts->forMonth($agentId, $month);
        $grade = PolicyGrade::query()
            ->where('policy_system_id', $current->policySystemId)
            ->where('is_active', true)
            ->where('monthly_threshold_krw', '<=', $commissionKrw)
            ->orderByDesc('monthly_threshold_krw')
            ->orderByDesc('sort_order')
            ->first() ?? PolicyGrade::query()->findOrFail($current->policyGradeId);

        return new SettlementAgentData(
            id: $current->agentId,
            code: $current->agentCode,
            name: $current->agentName,
            policySystemId: $current->policySystemId,
            currentGradeId: (int) $grade->id,
            currentGradeName: (string) $grade->name,
            currentGradeThresholdKrw: (int) $grade->monthly_threshold_krw,
        );
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
            currentGradeThresholdKrw: (int) PolicyGrade::query()->findOrFail($context->policyGradeId)->monthly_threshold_krw,
        );
    }
}
