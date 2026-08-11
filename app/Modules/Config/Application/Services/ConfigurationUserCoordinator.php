<?php

namespace App\Modules\Config\Application\Services;

use App\Modules\Auth\Application\Contracts\UserManagementGateway;

final readonly class ConfigurationUserCoordinator
{
    public function __construct(private UserManagementGateway $users) {}

    /** @return array<int, array<string, mixed>> */
    public function users(): array
    {
        return $this->users->users();
    }

    /** @return array{id: int, invitation_status: string} */
    public function invite(string $name, string $email, bool $isSuperAdmin, int $actorId, ?string $ipAddress): array
    {
        return $this->users->invite($name, $email, $isSuperAdmin, $actorId, $ipAddress);
    }

    public function resend(int $userId, int $actorId, ?string $ipAddress): string
    {
        return $this->users->resendInvitation($userId, $actorId, $ipAddress);
    }

    public function sendPasswordResetLink(int $userId, int $actorId, ?string $ipAddress): string
    {
        return $this->users->sendPasswordResetLink($userId, $actorId, $ipAddress);
    }

    public function changeRole(int $userId, bool $isSuperAdmin, int $actorId, ?string $ipAddress): void
    {
        $this->users->changeRole($userId, $isSuperAdmin, $actorId, $ipAddress);
    }

    public function setActive(int $userId, bool $active, int $actorId, ?string $ipAddress): void
    {
        $this->users->setActive($userId, $active, $actorId, $ipAddress);
    }
}
