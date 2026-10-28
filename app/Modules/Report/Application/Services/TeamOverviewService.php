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
    public function snapshot(
        ?int $requestedGroupId = null,
        ?string $requestedMonth = null,
        ?int $requestedBdManagerId = null,
    ): array {
        $context = $this->access->current();
        abort_unless($context->hasOrganizationWideReadAccess() || $context->isBdManager(), 403);

        $now = $this->clock->now();
        $date = $now->toDateString();
        $currentMemberships = $this->groups->memberships($date);
        $eligibleUserIds = array_map(
            fn (array $user): int => (int) $user['id'],
            $this->userReferences->eligibleUsers(),
        );
        $groups = array_values(array_filter(
            $this->groups->businessGroups(),
            fn (array $group): bool => (bool) $group['is_active']
                && ($context->hasOrganizationWideReadAccess() || in_array((int) $group['id'], $context->businessGroupIds, true)),
        ));
        $bdManagersByGroup = [];
        $bdManagerOptions = [];
        foreach ($currentMemberships as $membership) {
            $userId = (int) ($membership['user_id'] ?? 0);
            if (! (bool) ($membership['is_current'] ?? false)
                || ($membership['member_role'] ?? null) !== 'bd_manager'
                || ! in_array($userId, $eligibleUserIds, true)) {
                continue;
            }

            $groupId = (int) ($membership['business_group_id'] ?? 0);
            $bdManagersByGroup[$groupId] = $userId;
            $bdManagerOptions[$userId] = [
                'id' => $userId,
                'name' => (string) ($membership['user_name'] ?? ''),
            ];
        }
        $allowedGroupIds = array_map(fn (array $group): int => (int) $group['id'], $groups);
        $allowedBdManagerIds = array_values(array_unique(array_map(
            fn (array $group): int => (int) ($bdManagersByGroup[(int) $group['id']] ?? 0),
            $groups,
        )));
        $allowedBdManagerIds = array_values(array_filter($allowedBdManagerIds, static fn (int $id): bool => $id > 0));
        $bdManagerOptions = array_values(array_filter(
            $bdManagerOptions,
            fn (array $manager): bool => in_array((int) $manager['id'], $allowedBdManagerIds, true),
        ));
        usort($bdManagerOptions, static fn (array $left, array $right): int => strcmp($left['name'], $right['name']));
        if ($requestedBdManagerId !== null && ! in_array($requestedBdManagerId, $allowedBdManagerIds, true)) {
            abort(404);
        }
        if ($requestedGroupId !== null && ! in_array($requestedGroupId, $allowedGroupIds, true)) {
            abort(404);
        }
        if ($requestedBdManagerId !== null) {
            $groups = array_values(array_filter(
                $groups,
                fn (array $group): bool => ($bdManagersByGroup[(int) $group['id']] ?? null) === $requestedBdManagerId,
            ));
        }
        if ($requestedGroupId !== null && ! in_array(
            $requestedGroupId,
            array_map(fn (array $group): int => (int) $group['id'], $groups),
            true,
        )) {
            abort(404);
        }

        $activeAgentIds = array_map(
            fn (array $agent): int => (int) $agent['id'],
            $this->agentReferences->activeAgents(),
        );
        $month = $requestedMonth ?? $now->format('Y-m');
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $month) !== 1 || $month > $now->format('Y-m')) {
            abort(404);
        }
        [$year, $monthNumber] = array_map('intval', explode('-', $month));
        $from = $now->setDate($year, $monthNumber, 1)->startOfMonth();
        $to = $from->isSameMonth($now) ? $now : $from->endOfMonth();
        $groupSummaries = array_map(
            fn (array $group): array => $this->groupSummary($group, $currentMemberships, $context, $from, $to, $eligibleUserIds, $activeAgentIds),
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
            'is_global' => $context->hasOrganizationWideReadAccess(),
            'groups' => $groupSummaries,
            'selected_group_id' => $selectedGroupId,
            'selected_group' => $selectedGroup,
            'overview' => $selectedGroup === null ? $this->overview($groupSummaries) : $this->groupKpis($selectedGroup),
            'month' => $month,
            'month_label' => $from->format('Y-m'),
            'bd_manager_id' => $requestedBdManagerId,
            'bd_managers' => $bdManagerOptions,
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
        $activeGroupAgentIds = array_values(array_intersect($agentIds, $activeAgentIds));
        $names = $this->users->namesByIds($memberIds);
        $bdMembership = collect($currentMembers)->first(
            fn (array $membership): bool => ($membership['member_role'] ?? null) === 'bd_manager'
                && in_array((int) ($membership['user_id'] ?? 0), $eligibleUserIds, true),
        );
        $teamContext = $this->teamContext($viewer, $groupId, $memberIds, $agentIds);
        /** @var array{
         *     customers: array<string, mixed>,
         *     agent_customers: array<int, int>,
         *     reminders: array<string, mixed>,
         *     orders: array<string, mixed>,
         *     agent_performance: list<array{agent_id: int, code: string, name: string, orders: int, amount_krw: int, customers: int}>
         * } $stats
         */
        $stats = $this->access->using($teamContext, function () use ($ownerIds, $agentIds, $groupId, $from, $to): array {
            return [
                'customers' => $this->customers->teamOverview($ownerIds, $from, $to),
                'agent_customers' => $this->customers->agentCustomerPortfolio($agentIds),
                'reminders' => $this->reminders->teamOverview($ownerIds, $to),
                'orders' => $this->orders->teamOverview($ownerIds, $groupId, $from, $to),
                'agent_performance' => $this->orders->teamAgentPerformance($agentIds, $groupId, $from, $to),
            ];
        });
        $customerStats = $stats['customers'];
        $reminderStats = $stats['reminders'];
        $orderStats = $stats['orders'];
        $agentPerformanceById = [];
        foreach ($stats['agent_performance'] as $performanceRow) {
            $agentPerformanceById[$performanceRow['agent_id']] = $performanceRow;
        }
        $performanceAgentIds = array_values(array_unique([
            ...$agentIds,
            ...array_map(static fn (array $row): int => $row['agent_id'], $stats['agent_performance']),
        ]));
        $labelContext = $this->teamContext($viewer, $groupId, $memberIds, $performanceAgentIds);
        $agentLabels = $this->access->using(
            $labelContext,
            fn (): array => $this->agentReferences->agentsByIds($performanceAgentIds),
        );
        $agentPerformance = [];
        foreach ($performanceAgentIds as $agentId) {
            $row = $agentPerformanceById[$agentId] ?? ['code' => '', 'name' => '', 'orders' => 0, 'amount_krw' => 0, 'customers' => 0];
            $agentPerformance[] = [
                'id' => $agentId,
                'code' => $row['code'] !== '' ? $row['code'] : ($agentLabels[$agentId]['code'] ?? ''),
                'name' => $row['name'] !== '' ? $row['name'] : ($agentLabels[$agentId]['name'] ?? __('customers.fallback.unknown_agent')),
                'can_view_agent' => $viewer->hasOrganizationWideReadAccess() || in_array($agentId, $viewer->agentIds, true),
                'customer_pool' => (int) ($stats['agent_customers'][$agentId] ?? 0),
                'orders' => (int) $row['orders'],
                'customers' => (int) $row['customers'],
                'amount_krw' => (int) $row['amount_krw'],
            ];
        }
        usort($agentPerformance, static fn (array $left, array $right): int => $right['amount_krw'] <=> $left['amount_krw'] ?: strcmp($left['name'], $right['name']));
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
            'agent_count' => count($activeGroupAgentIds),
            'customer_service_count' => count($ownerIds),
            'customer_count' => (int) $customerStats['total_customers'],
            'new_customers' => (int) $customerStats['new_customers'],
            'pending_reminders' => (int) $reminderStats['pending'],
            'overdue_reminders' => (int) $reminderStats['overdue'],
            'amount_krw' => (int) $orderStats['amount_krw'],
            'orders' => (int) $orderStats['orders'],
            'month' => $from->format('Y-m'),
            'period_from' => $from->toDateString(),
            'period_to' => $to->toDateString(),
            'agent_customer_count' => array_sum($stats['agent_customers']),
            'agent_performance' => $agentPerformance,
            'unassigned_customers' => (int) $customerStats['unassigned_customers'],
            'owner_exception_customers' => (int) $customerStats['owner_exception_customers'],
            'pending_transfer_requests' => (int) $customerStats['pending_transfer_requests'],
            'owners' => $owners,
            'attention' => [
                'missing_bd' => $bdMembership === null,
                'missing_customer_service' => $ownerIds === [],
                'overdue_reminders' => (int) $reminderStats['overdue'],
                'unassigned_customers' => (int) $customerStats['unassigned_customers'],
                'owner_exception_customers' => (int) $customerStats['owner_exception_customers'],
                'pending_transfer_requests' => (int) $customerStats['pending_transfer_requests'],
            ],
            'has_attention' => $bdMembership === null
                || $ownerIds === []
                || (int) $reminderStats['overdue'] > 0
                || (int) $customerStats['unassigned_customers'] > 0
                || (int) $customerStats['owner_exception_customers'] > 0
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
            'agent_customer_count' => (int) $group['agent_customer_count'],
            'customers' => (int) $group['customer_count'],
            'new_customers' => (int) $group['new_customers'],
            'pending_reminders' => (int) $group['pending_reminders'],
            'overdue_reminders' => (int) $group['overdue_reminders'],
            'amount_krw' => (int) $group['amount_krw'],
            'unassigned_customers' => (int) $group['unassigned_customers'],
            'owner_exception_customers' => (int) $group['owner_exception_customers'],
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
            'agent_customer_count' => array_sum(array_column($groups, 'agent_customer_count')),
            'customers' => array_sum(array_column($groups, 'customer_count')),
            'new_customers' => array_sum(array_column($groups, 'new_customers')),
            'pending_reminders' => array_sum(array_column($groups, 'pending_reminders')),
            'overdue_reminders' => array_sum(array_column($groups, 'overdue_reminders')),
            'amount_krw' => array_sum(array_column($groups, 'amount_krw')),
            'unassigned_customers' => array_sum(array_column($groups, 'unassigned_customers')),
            'owner_exception_customers' => array_sum(array_column($groups, 'owner_exception_customers')),
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
