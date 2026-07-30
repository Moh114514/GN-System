<?php

namespace App\Modules\Agent\Application\Services;

use App\Modules\Agent\Application\Contracts\AgentReferenceReader;
use App\Modules\Agent\Infrastructure\Models\Agent;
use App\Modules\Agent\Infrastructure\Models\AgentTypeCode;
use App\Modules\Agent\Infrastructure\Models\PolicyGrade;
use App\Modules\Agent\Infrastructure\Models\PolicySystem;
use App\Modules\Config\Application\Contracts\InstitutionReferenceReader;
use App\Modules\Settlement\Application\Contracts\CommissionConfigurationGateway;
use Carbon\CarbonImmutable;

final readonly class AgentConfigurationCoordinator
{
    public function __construct(
        private AgentReferenceReader $agentReferences,
        private InstitutionReferenceReader $institutions,
        private CommissionConfigurationGateway $commissions,
    ) {}

    /** @return array<string, mixed> */
    public function state(
        string $gradeSort = 'configured',
        string $ruleSort = 'effective_desc',
        string $overrideSort = 'effective_desc',
    ): array {
        $systems = PolicySystem::query()->orderBy('name')->get()->toArray();
        $grades = $this->grades($gradeSort);
        $agents = array_values($this->agentReferences->agentsByIds(
            Agent::query()->pluck('id')->map(fn ($id): int => (int) $id)->all(),
        ));
        $institutions = array_values($this->institutions->activeInstitutions());
        $commissionConfiguration = $this->commissions->configuration();

        return [
            'types' => AgentTypeCode::query()->orderBy('code')->get()->toArray(),
            'systems' => $systems,
            'grades' => $grades,
            'agents' => $agents,
            'institutions' => $institutions,
            'rules' => $this->sortRules(
                $commissionConfiguration['rules'],
                $ruleSort,
                $grades,
                $institutions,
            ),
            'overrides' => $this->sortOverrides(
                $commissionConfiguration['overrides'],
                $overrideSort,
                $agents,
                $institutions,
            ),
        ];
    }

    public function saveRule(int $gradeId, int $institutionId, int $rateBps, CarbonImmutable $month, int $actorId, ?string $ipAddress): void
    {
        $this->commissions->saveRule($gradeId, $institutionId, $rateBps, $month, $actorId, $ipAddress);
    }

    public function saveOverride(int $agentId, ?int $institutionId, int $rateBps, CarbonImmutable $month, string $reason, int $actorId, ?string $ipAddress): void
    {
        $this->commissions->saveOverride($agentId, $institutionId, $rateBps, $month, $reason, $actorId, $ipAddress);
    }

    /** @return array<int, array<string, mixed>> */
    private function grades(string $sort): array
    {
        $query = PolicyGrade::query();

        match ($sort) {
            'threshold_asc' => $query->orderBy('monthly_threshold_krw')->orderBy('name'),
            'threshold_desc' => $query->orderByDesc('monthly_threshold_krw')->orderBy('name'),
            'name_asc' => $query->orderBy('name'),
            'name_desc' => $query->orderByDesc('name'),
            'sort_desc' => $query->orderByDesc('sort_order')->orderBy('name'),
            default => $query->orderBy('policy_system_id')->orderBy('sort_order')->orderBy('name'),
        };

        return $query->get()->toArray();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rules
     * @param  array<int, array<string, mixed>>  $grades
     * @param  array<int, array<string, mixed>>  $institutions
     * @return array<int, array<string, mixed>>
     */
    private function sortRules(array $rules, string $sort, array $grades, array $institutions): array
    {
        $gradeNames = collect($grades)->pluck('name', 'id');
        $institutionNames = collect($institutions)->pluck('name', 'id');

        usort($rules, function (array $left, array $right) use ($sort, $gradeNames, $institutionNames): int {
            $comparison = match ($sort) {
                'rate_asc' => (int) $left['rate_bps'] <=> (int) $right['rate_bps'],
                'rate_desc' => (int) $right['rate_bps'] <=> (int) $left['rate_bps'],
                'effective_asc' => strcmp((string) $left['effective_month'], (string) $right['effective_month']),
                'grade_asc' => strnatcasecmp(
                    (string) $gradeNames->get($left['policy_grade_id'], ''),
                    (string) $gradeNames->get($right['policy_grade_id'], ''),
                ),
                'institution_asc' => strnatcasecmp(
                    (string) $institutionNames->get($left['institution_id'], ''),
                    (string) $institutionNames->get($right['institution_id'], ''),
                ),
                default => strcmp((string) $right['effective_month'], (string) $left['effective_month']),
            };

            return $comparison !== 0 ? $comparison : (int) $left['id'] <=> (int) $right['id'];
        });

        return $rules;
    }

    /**
     * @param  array<int, array<string, mixed>>  $overrides
     * @param  array<int, array<string, mixed>>  $agents
     * @param  array<int, array<string, mixed>>  $institutions
     * @return array<int, array<string, mixed>>
     */
    private function sortOverrides(array $overrides, string $sort, array $agents, array $institutions): array
    {
        $agentNames = collect($agents)->pluck('name', 'id');
        $institutionNames = collect($institutions)->pluck('name', 'id');

        usort($overrides, function (array $left, array $right) use ($sort, $agentNames, $institutionNames): int {
            $comparison = match ($sort) {
                'rate_asc' => (int) $left['rate_bps'] <=> (int) $right['rate_bps'],
                'rate_desc' => (int) $right['rate_bps'] <=> (int) $left['rate_bps'],
                'effective_asc' => strcmp((string) $left['effective_from'], (string) $right['effective_from']),
                'agent_asc' => strnatcasecmp(
                    (string) $agentNames->get($left['agent_id'], ''),
                    (string) $agentNames->get($right['agent_id'], ''),
                ),
                'institution_asc' => strnatcasecmp(
                    (string) $institutionNames->get($left['institution_id'], ''),
                    (string) $institutionNames->get($right['institution_id'], ''),
                ),
                default => strcmp((string) $right['effective_from'], (string) $left['effective_from']),
            };

            return $comparison !== 0 ? $comparison : (int) $left['id'] <=> (int) $right['id'];
        });

        return $overrides;
    }
}
