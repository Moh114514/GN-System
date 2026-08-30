<?php

namespace App\Modules\Report\Application\Services;

use App\Infrastructure\Time\BusinessClock;
use App\Modules\Agent\Application\Contracts\AgentAccessScopeReader;
use App\Modules\Agent\Application\Contracts\AgentReferenceReader;
use App\Modules\Auth\Application\Contracts\AccessContextResolver;
use App\Modules\Auth\Application\Contracts\BusinessGroupManagementGateway;
use App\Modules\Auth\Application\Contracts\BusinessGroupMembershipReader;
use App\Modules\Auth\Application\Contracts\InternalUserReferenceReader;
use App\Modules\Auth\Application\Contracts\ReportUserReader;
use App\Modules\Auth\Application\Data\AccessContext;
use App\Modules\Customer\Application\Contracts\ReportCustomerReader;
use App\Modules\Order\Application\Contracts\ReportOrderReader;
use App\Modules\Reminder\Application\Contracts\ReportReminderReader;
use Carbon\CarbonImmutable;

final readonly class TeamOverviewService
{
    public function __construct(
        private AccessContextResolver $access,
        private BusinessGroupManagementGateway $groups,
        private BusinessGroupMembershipReader $memberships,
        private InternalUserReferenceReader $userReferences,
        private AgentAccessScopeReader $agents,
        private AgentReferenceReader $agentReferences,
        private ReportUserReader $users,
        private ReportCustomerReader $customers,
        private ReportReminderReader $reminders,
        private ReportOrderReader $orders,
        private BusinessClock $clock,
    ) {}

    /** @return array<string, mixed> */
    public function snapshot(?int $requestedGroupId = null): array
    {
        $context = $this->access->current();
        abort_unless($context->isSuperAdmin() || $context->isBdManager(), 403);

        $now = $this->clock->now();
        $date = $now->toDateString();
        $groups = array_values(array_filter(
            $this->groups->businessGroups(),
            fn (array $group): bool => (bool) $group['is_active']
                && ($context->isSuperAdmin() || in_array((int) $group['id'], $context->businessGroupIds, true)),
        ));
        $allowedGroupIds = array_map(fn (array $group): int => (int) $group['id'], $groups);
        if ($requestedGroupId !== null && ! in_array($requestedGroupId, $allowedGroupIds, true)) {
            abort(404);
        }

        $currentMemberships = $this->groups->memberships($date);
        $eligibleUserIds = array_map(
            fn (array $user): int => (int) $user['id'],
            $this->userReferences->eligibleUsers(),
        );
        $activeAgentIds = array_map(
            fn (array $agent): int => (int) $agent['id'],
            $this->agentReferences->activeAgents(),
        );
        $from = $now->startOfMonth();
        $groupSummaries = array_map(
            fn (array $group): array => $this->groupSummary($group, $currentMemberships, $context, $from, $now, $eligibleUserIds, $activeAgentIds),
            $groups,
        );
        $selectedGroupId = $requestedGroupId;
        if ($selectedGroupId === null && $context->isBdManager() && count($groupSummaries) === 1) {
            $selectedGroupId = (int) $groupSummaries[0]['id'];
        }
        $selectedGroup = $selectedGroupId === null
            ? null
            : collect($groupSummaries)->firstWhere('id', $selectedGroupId);

        return [
            'is_global' => $context->isSuperAdmin(),
            'groups' => $groupSummaries,
            'selected_group_id' => $selectedGroupId,
            'selected_group' => $selectedGroup,
            'overview' => $selectedGroup === null ? $this->overview($groupSummaries) : $this->groupKpis($selectedGroup),
            'generated_at' => $now->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @param  array<string, mixed>  $group
     * @param  array<int, array<string, mixed>>  $memberships
     * @param  list<int>  $eligibleUserIds
     * @param  list<int>  $activeAgentIds
     * @return array<string, mixed>
     */
    private function groupSummary(
        array $group,
        array $memberships,
        AccessContext $viewer,
        CarbonImmutable $from,
        CarbonImmutable $to,
        array $eligibleUserIds,
        array $activeAgentIds,
    ): array {
        $groupId = (int) $group['id'];
        $currentMembers = array_values(array_filter(
            $memberships,
            fn (array $membership): bool => (int) ($membership['business_group_id'] ?? 0) === $groupId
                && (bool) ($membership['is_current'] ?? false),
        ));
        $ownerIds = $this->memberships->activeCustomerServiceUserIds([$groupId], $to->toDateString());
        $memberIds = array_values(array_unique(array_map(
            fn (array $membership): int => (int) $membership['user_id'],
            $currentMembers,
        )));
        $agentIds = $this->agents->agentIdsForBusinessGroups([$groupId], $to->toDateString());
        $agentIds = array_values(array_intersect($agentIds, $activeAgentIds));
        $names = $this->users->namesByIds($memberIds);
        $bdMembership = collect($currentMembers)->first(
            fn (array $membership): bool => ($membership['member_role'] ?? null) === 'bd_manager'
                && in_array((int) ($membership['user_id'] ?? 0), $eligibleUserIds, true),
        );
        $teamContext = $this->teamContext($viewer, $groupId, $memberIds, $agentIds);
        $stats = $this->access->using($teamContext, function () use ($ownerIds, $groupId, $from, $to): array {
            return [
                'customers' => $this->customers->teamOverview($ownerIds, $from, $to),
                'reminders' => $this->reminders->teamOverview($ownerIds, $to),
                'orders' => $this->orders->teamOverview($ownerIds, $groupId, $from, $to),
            ];
        });
        $customerStats = $stats['customers'];
        $reminderStats = $stats['reminders'];
        $orderStats = $stats['orders'];
        $owners = [];
        foreach ($ownerIds as $ownerId) {
            $customer = $customerStats['owners'][$ownerId] ?? [
                'customers' => 0,
                'new_customers' => 0,
                'unset' => 0,
                'booked' => 0,
                'arrived' => 0,
                'treatment_completed' => 0,
            ];
            $reminder = $reminderStats['owners'][$ownerId] ?? ['pending' => 0, 'overdue' => 0];
            $order = $orderStats['owners'][$ownerId] ?? ['orders' => 0, 'amount_krw' => 0];
            $owners[] = [
                'id' => $ownerId,
                'name' => $names[$ownerId] ?? __('customers.fallback.unknown_owner'),
                ...$customer,
                'pending_reminders' => $reminder['pending'],
                'overdue_reminders' => $reminder['overdue'],
                'orders' => $order['orders'],
                'amount_krw' => $order['amount_krw'],
            ];
        }

        return [
            'id' => $groupId,
            'code' => (string) $group['code'],
            'name' => (string) $group['name'],
            'bd_name' => $bdMembership === null ? __('customers.fallback.unset') : ($names[(int) $bdMembership['user_id']] ?? __('customers.fallback.unknown_owner')),
            'agent_count' => count($agentIds),
            'customer_service_count' => count($ownerIds),
            'customer_count' => (int) $customerStats['total_customers'],
            'new_customers' => (int) $customerStats['new_customers'],
            'pending_reminders' => (int) $reminderStats['pending'],
            'overdue_reminders' => (int) $reminderStats['overdue'],
            'amount_krw' => (int) $orderStats['amount_krw'],
            'orders' => (int) $orderStats['orders'],
            'unassigned_customers' => (int) $customerStats['unassigned_customers'],
            'pending_transfer_requests' => (int) $customerStats['pending_transfer_requests'],
            'owners' => $owners,
            'attention' => [
                'missing_bd' => $bdMembership === null,
                'missing_customer_service' => $ownerIds === [],
                'overdue_reminders' => (int) $reminderStats['overdue'],
                'unassigned_customers' => (int) $customerStats['unassigned_customers'],
                'pending_transfer_requests' => (int) $customerStats['pending_transfer_requests'],
            ],
            'has_attention' => $bdMembership === null
                || $ownerIds === []
                || (int) $reminderStats['overdue'] > 0
                || (int) $customerStats['unassigned_customers'] > 0
                || (int) $customerStats['pending_transfer_requests'] > 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $group
     * @return array<string, int>
     */
    private function groupKpis(array $group): array
    {
        return [
            'groups' => 1,
            'agents' => (int) $group['agent_count'],
            'customer_service' => (int) $group['customer_service_count'],
            'customers' => (int) $group['customer_count'],
            'new_customers' => (int) $group['new_customers'],
            'pending_reminders' => (int) $group['pending_reminders'],
            'overdue_reminders' => (int) $group['overdue_reminders'],
            'amount_krw' => (int) $group['amount_krw'],
            'unassigned_customers' => (int) $group['unassigned_customers'],
            'pending_transfer_requests' => (int) $group['pending_transfer_requests'],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $groups
     * @return array<string, int>
     */
    private function overview(array $groups): array
    {
        return [
            'groups' => count($groups),
            'agents' => array_sum(array_column($groups, 'agent_count')),
            'customer_service' => array_sum(array_column($groups, 'customer_service_count')),
            'customers' => array_sum(array_column($groups, 'customer_count')),
            'new_customers' => array_sum(array_column($groups, 'new_customers')),
            'pending_reminders' => array_sum(array_column($groups, 'pending_reminders')),
            'overdue_reminders' => array_sum(array_column($groups, 'overdue_reminders')),
            'amount_krw' => array_sum(array_column($groups, 'amount_krw')),
            'unassigned_customers' => array_sum(array_column($groups, 'unassigned_customers')),
            'pending_transfer_requests' => array_sum(array_column($groups, 'pending_transfer_requests')),
        ];
    }

    /**
     * @param  list<int>  $memberIds
     * @param  list<int>  $agentIds
     */
    private function teamContext(AccessContext $viewer, int $groupId, array $memberIds, array $agentIds): AccessContext
    {
        return new AccessContext(
            userId: $viewer->isSuperAdmin() ? null : $viewer->userId,
            role: 'bd_manager',
            businessGroupIds: [$groupId],
            agentIds: $agentIds,
            groupUserIds: $memberIds,
            unrestricted: false,
        );
    }
}
