<?php

namespace App\Modules\Agent\Application\Contracts;

interface AgentBusinessGroupAssignmentGateway
{
    /** @return array<int, array{id: int, code: string, name: string, status: string}> */
    public function agents(): array;

    /** @return array<int, array<string, mixed>> */
    public function assignments(): array;

    /** @return array<int, array<string, mixed>> */
    public function unassignedAgents(?string $onDate = null): array;

    public function assign(
        int $agentId,
        int $businessGroupId,
        string $effectiveFrom,
        ?string $effectiveUntil,
        string $reason,
        int $actorId,
        ?string $ipAddress,
    ): void;

    public function endAssignment(int $assignmentId, string $effectiveUntil, string $reason, int $actorId, ?string $ipAddress): void;
}
