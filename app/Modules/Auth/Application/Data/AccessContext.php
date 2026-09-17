<?php

namespace App\Modules\Auth\Application\Data;

use App\Modules\Auth\Domain\UserRole;

final readonly class AccessContext
{
    /**
     * @param  list<int>  $businessGroupIds
     * @param  list<int>  $agentIds
     * @param  list<int>  $groupUserIds
     */
    public function __construct(
        public ?int $userId,
        public string $role,
        public array $businessGroupIds,
        public array $agentIds,
        public array $groupUserIds = [],
        public bool $unrestricted = false,
        public string $fingerprint = '',
    ) {}

    public function isSuperAdmin(): bool
    {
        return $this->unrestricted || $this->role === UserRole::SuperAdmin->value;
    }

    public function isBdManager(): bool
    {
        return ! $this->isSuperAdmin() && $this->role === UserRole::BdManager->value;
    }

    public function isCustomerService(): bool
    {
        return ! $this->isSuperAdmin() && $this->role === UserRole::CustomerService->value;
    }

    public function hasEffectiveBusinessScope(): bool
    {
        return $this->isSuperAdmin()
            || ($this->businessGroupIds !== [] && $this->agentIds !== []);
    }

    public function canViewAgent(int $agentId): bool
    {
        return $this->isSuperAdmin()
            || ($this->hasEffectiveBusinessScope() && in_array($agentId, $this->agentIds, true));
    }

    public function canViewCustomer(?int $sourceAgentId, ?int $ownerId): bool
    {
        return $this->isSuperAdmin()
            || ($this->hasEffectiveBusinessScope() && (
                ($ownerId !== null && $this->userId === $ownerId)
                || ($sourceAgentId !== null && in_array($sourceAgentId, $this->agentIds, true))
            ));
    }

    public function canViewOrder(?int $agentId, ?int $customerOwnerId = null, ?int $customerSourceAgentId = null): bool
    {
        return $this->isSuperAdmin()
            || ($this->hasEffectiveBusinessScope() && (
                ($agentId !== null && in_array($agentId, $this->agentIds, true))
                || ($customerOwnerId !== null && $this->userId === $customerOwnerId)
                || ($customerSourceAgentId !== null && in_array($customerSourceAgentId, $this->agentIds, true))
            ));
    }

    public function canDownloadSensitiveCustomerData(?int $ownerId): bool
    {
        return $this->isSuperAdmin()
            || ($this->hasEffectiveBusinessScope() && $ownerId !== null && $ownerId === $this->userId);
    }

    /** @return array<string, mixed> */
    public function toSnapshot(): array
    {
        return [
            'user_id' => $this->userId,
            'role' => $this->role,
            'business_group_ids' => $this->businessGroupIds,
            'agent_ids' => $this->agentIds,
            'group_user_ids' => $this->groupUserIds,
            'unrestricted' => $this->unrestricted,
            'fingerprint' => $this->fingerprint,
        ];
    }

    /** @param array<string, mixed> $snapshot */
    public static function fromSnapshot(array $snapshot): self
    {
        $userId = isset($snapshot['user_id']) ? (int) $snapshot['user_id'] : null;
        $role = is_string($snapshot['role'] ?? null) ? $snapshot['role'] : UserRole::CustomerService->value;
        $groupIds = self::ids($snapshot['business_group_ids'] ?? []);
        $agentIds = self::ids($snapshot['agent_ids'] ?? []);
        $groupUserIds = self::ids($snapshot['group_user_ids'] ?? []);
        $unrestricted = (bool) ($snapshot['unrestricted'] ?? false);
        $fingerprint = is_string($snapshot['fingerprint'] ?? null) ? $snapshot['fingerprint'] : '';

        return new self($userId, $role, $groupIds, $agentIds, $groupUserIds, $unrestricted, $fingerprint);
    }

    /** @return list<int> */
    private static function ids(mixed $ids): array
    {
        if (! is_array($ids)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('intval', $ids), fn (int $id): bool => $id > 0)));
    }
}
