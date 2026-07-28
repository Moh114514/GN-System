<?php

namespace App\Modules\Agent\Application\Data;

use Carbon\CarbonImmutable;

final readonly class AgentCommissionContextData
{
    public function __construct(
        public int $agentId,
        public string $agentCode,
        public string $agentName,
        public int $policySystemId,
        public string $policySystemName,
        public int $policyGradeId,
        public string $policyGradeName,
        public int $assignmentId,
        public CarbonImmutable $effectiveMonth,
    ) {}
}
