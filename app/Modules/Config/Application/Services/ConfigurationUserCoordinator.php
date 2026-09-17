<?php

namespace App\Modules\Config\Application\Services;

use App\Modules\Agent\Application\Contracts\AgentBusinessGroupAssignmentGateway;
use App\Modules\Auth\Application\Contracts\BusinessGroupManagementGateway;
use App\Modules\Auth\Application\Contracts\UserManagementGateway;
use DomainException;

final readonly class ConfigurationUserCoordinator
{
    public function __construct(
        private UserManagementGateway $users,
        private BusinessGroupManagementGateway $businessGroups,
        private AgentBusinessGroupAssignmentGateway $agentAssignments,
    ) {}

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

    /** @return array{id: int, invitation_status: string} */
    public function inviteWithRole(string $name, string $email, string $role, int $actorId, ?string $ipAddress): array
    {
        return $this->users->inviteWithRole($name, $email, $role, $actorId, $ipAddress);
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

    public function setRole(int $userId, string $role, int $actorId, ?string $ipAddress): void
    {
        $this->users->setRole($userId, $role, $actorId, $ipAddress);
    }

    public function setActive(int $userId, bool $active, int $actorId, ?string $ipAddress): void
    {
        $this->users->setActive($userId, $active, $actorId, $ipAddress);
    }

    public function setDingTalkMention(int $userId, ?string $dingtalkMentionType, ?string $dingtalkMentionValue, int $actorId, ?string $ipAddress): void
    {
        $this->users->setDingTalkMention($userId, $dingtalkMentionType, $dingtalkMentionValue, $actorId, $ipAddress);
    }

    /** @return array<int, array<string, mixed>> */
    public function businessGroups(): array
    {
        return $this->businessGroups->businessGroups();
    }

    /** @return array<int, array<string, mixed>> */
    public function businessGroupSummaries(): array
    {
        $memberships = collect($this->businessGroups->memberships());
        $assignments = collect($this->agentAssignments->assignments());

        return array_map(function (array $group) use ($memberships, $assignments): array {
            $groupMembers = $memberships->where('business_group_id', $group['id']);
            $groupAgents = $assignments->where('business_group_id', $group['id']);
            $currentBd = $groupMembers->first(fn (array $membership): bool => $membership['member_role'] === 'bd_manager' && $membership['is_current']);

            return [
                ...$group,
                'current_bd_name' => $currentBd['user_name'] ?? null,
                'customer_service_count' => $groupMembers->where('member_role', 'customer_service')->where('is_current', true)->count(),
                'agent_count' => $groupAgents->where('is_current', true)->count(),
            ];
        }, $this->businessGroups->businessGroups());
    }

    /** @return array<string, mixed>|null */
    public function businessGroupDetails(int $businessGroupId): ?array
    {
        $group = collect($this->businessGroups->businessGroups())->firstWhere('id', $businessGroupId);
        if ($group === null) {
            return null;
        }
        $memberships = array_values(array_filter(
            $this->businessGroups->memberships(),
            static fn (array $membership): bool => (int) $membership['business_group_id'] === $businessGroupId,
        ));
        $assignments = array_values(array_filter(
            $this->agentAssignments->assignments(),
            static fn (array $assignment): bool => (int) $assignment['business_group_id'] === $businessGroupId,
        ));

        return [
            'group' => $group,
            'memberships' => $memberships,
            'assignments' => $assignments,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function memberships(?string $onDate = null): array
    {
        return $this->businessGroups->memberships($onDate);
    }

    /** @return array<int, array<string, mixed>> */
    public function unassignedUsers(?string $onDate = null): array
    {
        return $this->businessGroups->unassignedUsers($onDate);
    }

    /** @return array<int, array<string, mixed>> */
    public function memberCandidates(?string $onDate = null): array
    {
        return $this->businessGroups->memberCandidates($onDate);
    }

    /** @return array{id: int, code: string, name: string, is_active: bool} */
    public function createBusinessGroup(string $code, string $name, int $actorId, ?string $ipAddress): array
    {
        return $this->businessGroups->create($code, $name, $actorId, $ipAddress);
    }

    /** @return array{id: int, code: string, name: string, is_active: bool} */
    public function updateBusinessGroupName(int $businessGroupId, string $name, int $actorId, ?string $ipAddress): array
    {
        return $this->businessGroups->updateName($businessGroupId, $name, $actorId, $ipAddress);
    }

    public function replaceBusinessGroupBd(int $businessGroupId, int $newBdUserId, string $effectiveFrom, string $reason, int $actorId, ?string $ipAddress): void
    {
        $this->businessGroups->replaceBd($businessGroupId, $newBdUserId, $effectiveFrom, $reason, $actorId, $ipAddress);
    }

    public function deactivateBusinessGroup(int $businessGroupId, string $reason, int $actorId, ?string $ipAddress): void
    {
        $hasCurrentAgents = collect($this->agentAssignments->assignments())
            ->contains(fn (array $assignment): bool => (int) $assignment['business_group_id'] === $businessGroupId && (bool) ($assignment['is_current'] ?? false));
        if ($hasCurrentAgents) {
            throw new DomainException(__('auth.errors.business_group_agents_must_be_reassigned'));
        }

        $this->businessGroups->deactivate($businessGroupId, $reason, $actorId, $ipAddress);
    }

    public function assignBusinessGroupMember(int $businessGroupId, int $userId, string $effectiveFrom, ?string $effectiveUntil, string $reason, int $actorId, ?string $ipAddress): void
    {
        $this->businessGroups->assignMember($businessGroupId, $userId, $effectiveFrom, $effectiveUntil, $reason, $actorId, $ipAddress);
    }

    public function endBusinessGroupMembership(int $membershipId, string $effectiveUntil, string $reason, int $actorId, ?string $ipAddress): void
    {
        $this->businessGroups->endMembership($membershipId, $effectiveUntil, $reason, $actorId, $ipAddress);
    }

    /** @return array<int, array<string, mixed>> */
    public function agents(): array
    {
        return $this->agentAssignments->agents();
    }

    /** @return array<int, array<string, mixed>> */
    public function agentAssignments(): array
    {
        return $this->agentAssignments->assignments();
    }

    /** @return array<int, array<string, mixed>> */
    public function unassignedAgents(?string $onDate = null): array
    {
        return $this->agentAssignments->unassignedAgents($onDate);
    }

    public function assignAgentToBusinessGroup(int $agentId, int $businessGroupId, string $effectiveFrom, ?string $effectiveUntil, string $reason, int $actorId, ?string $ipAddress): void
    {
        $this->agentAssignments->assign($agentId, $businessGroupId, $effectiveFrom, $effectiveUntil, $reason, $actorId, $ipAddress);
    }

    public function endAgentBusinessGroupAssignment(int $assignmentId, string $effectiveUntil, string $reason, int $actorId, ?string $ipAddress): void
    {
        $this->agentAssignments->endAssignment($assignmentId, $effectiveUntil, $reason, $actorId, $ipAddress);
    }
}
