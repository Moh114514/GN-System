<?php

namespace App\Modules\Auth\Application\Contracts;

interface UserManagementGateway
{
    /** @return array<int, array<string, mixed>> */
    public function users(): array;

    /** @return array{id: int, invitation_status: string} */
    public function invite(string $name, string $email, bool $isSuperAdmin, int $actorId, ?string $ipAddress): array;

    public function resendInvitation(int $userId, int $actorId, ?string $ipAddress): string;

    public function sendPasswordResetLink(int $userId, int $actorId, ?string $ipAddress): string;

    public function changeRole(int $userId, bool $isSuperAdmin, int $actorId, ?string $ipAddress): void;

    public function setActive(int $userId, bool $active, int $actorId, ?string $ipAddress): void;

    public function setDingTalkUserId(int $userId, ?string $dingtalkUserId, int $actorId, ?string $ipAddress): void;
}
