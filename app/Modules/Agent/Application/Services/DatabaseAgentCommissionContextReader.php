<?php

namespace App\Modules\Agent\Application\Services;

use App\Modules\Agent\Application\Contracts\AgentCommissionContextReader;
use App\Modules\Agent\Application\Data\AgentCommissionContextData;
use App\Modules\Agent\Infrastructure\Models\Agent;
use App\Modules\Agent\Infrastructure\Models\AgentGradeAssignment;
use App\Modules\Agent\Infrastructure\Models\PolicyGrade;
use App\Modules\Agent\Infrastructure\Models\PolicySystem;
use Carbon\CarbonImmutable;
use DomainException;

final class DatabaseAgentCommissionContextReader implements AgentCommissionContextReader
{
    public function forMonth(int $agentId, CarbonImmutable $month): AgentCommissionContextData
    {
        $agent = Agent::query()->findOrFail($agentId);
        if ($agent->cooperation_status !== 'active') {
            throw new DomainException('代理商当前不是合作中状态，不能产生新订单或推广费。');
        }

        $effectiveMonth = $month->startOfMonth();
        $assignment = AgentGradeAssignment::query()
            ->where('agent_id', $agentId)
            ->whereDate('effective_month', '<=', $effectiveMonth)
            ->latest('effective_month')
            ->first();
        if ($assignment === null) {
            throw new DomainException('代理商在订单月份没有生效的政策等级。');
        }

        $grade = PolicyGrade::query()->findOrFail($assignment->policy_grade_id);
        $system = PolicySystem::query()->findOrFail($grade->policy_system_id);

        return new AgentCommissionContextData(
            agentId: (int) $agent->id,
            agentCode: (string) $agent->code,
            agentName: (string) $agent->name,
            policySystemId: (int) $system->id,
            policySystemName: (string) $system->name,
            policyGradeId: (int) $grade->id,
            policyGradeName: (string) $grade->name,
            assignmentId: (int) $assignment->id,
            effectiveMonth: CarbonImmutable::parse($assignment->effective_month)->startOfMonth(),
        );
    }
}
