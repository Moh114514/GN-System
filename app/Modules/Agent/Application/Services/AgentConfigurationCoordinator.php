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
    public function state(): array
    {
        return [
            'types' => AgentTypeCode::query()->orderBy('code')->get()->toArray(),
            'systems' => PolicySystem::query()->orderBy('name')->get()->toArray(),
            'grades' => PolicyGrade::query()->orderBy('policy_system_id')->orderBy('sort_order')->get()->toArray(),
            'agents' => array_values($this->agentReferences->agentsByIds(
                Agent::query()->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            )),
            'institutions' => array_values($this->institutions->activeInstitutions()),
            ...$this->commissions->configuration(),
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
}
