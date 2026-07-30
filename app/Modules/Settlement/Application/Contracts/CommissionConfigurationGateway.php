<?php

namespace App\Modules\Settlement\Application\Contracts;

use Carbon\CarbonImmutable;

interface CommissionConfigurationGateway
{
    /** @return array{rules: array<int, array<string, mixed>>, overrides: array<int, array<string, mixed>>} */
    public function configuration(): array;

    public function saveRule(
        int $policyGradeId,
        int $institutionId,
        int $rateBps,
        CarbonImmutable $effectiveMonth,
        int $actorId,
        ?string $ipAddress,
        bool $isActive = true,
    ): void;

    public function saveOverride(
        int $agentId,
        ?int $institutionId,
        int $rateBps,
        CarbonImmutable $effectiveMonth,
        string $reason,
        int $actorId,
        ?string $ipAddress,
    ): void;
}
