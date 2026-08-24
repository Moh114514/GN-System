<?php

namespace App\Modules\Auth\Application\Contracts;

interface BusinessGroupManagementGateway extends BusinessGroupReferenceReader
{
    /** @return array<int, array<string, mixed>> */
    public function memberships(?string $onDate = null): array;

    /** @return array<int, array<string, mixed>> */
    public function unassignedUsers(?string $onDate = null): array;

    /** @return array{id: int, code: string, name: string, is_active: bool} */
    public function create(string $code, string $name, int $actorId, ?string $ipAddress): array;

    public function assignMember(
        int $businessGroupId,
        int $userId,
        string $effectiveFrom,
        ?string $effectiveUntil,
        string $reason,
        int $actorId,
        ?string $ipAddress,
    ): void;

    public function endMembership(int $membershipId, string $effectiveUntil, string $reason, int $actorId, ?string $ipAddress): void;
}
