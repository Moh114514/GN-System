<?php

namespace App\Modules\Customer\Application\Services;

use App\Infrastructure\Time\BusinessClock;
use App\Modules\Agent\Application\Contracts\AgentAccessScopeReader;
use App\Modules\Agent\Application\Contracts\AgentReferenceReader;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Auth\Application\Contracts\AccessContextResolver;
use App\Modules\Auth\Application\Contracts\BusinessGroupMembershipReader;
use App\Modules\Auth\Application\Contracts\BusinessGroupReferenceReader;
use App\Modules\Auth\Application\Contracts\InternalUserReferenceReader;
use App\Modules\Auth\Application\Contracts\ReportUserReader;
use App\Modules\Auth\Application\Data\AccessContext;
use App\Modules\Config\Application\Contracts\InstitutionReferenceReader;
use App\Modules\Customer\Domain\BlindIndex;
use App\Modules\Customer\Domain\CustomerLabelLocalizer;
use App\Modules\Customer\Domain\SensitiveValueMasker;
use App\Modules\Customer\Infrastructure\Models\Customer;
use App\Modules\Customer\Infrastructure\Models\CustomerContact;
use App\Modules\Customer\Infrastructure\Models\CustomerLifecycleStage;
use App\Modules\Customer\Infrastructure\Models\CustomerOwnerHistory;
use App\Modules\Customer\Infrastructure\Models\CustomerStatus;
use App\Modules\Customer\Infrastructure\Models\CustomerStatusHistory;
use App\Modules\Customer\Infrastructure\Models\CustomerStatusTransition;
use App\Modules\Order\Application\Contracts\CustomerOrderGateway;
use App\Modules\Reminder\Application\Contracts\CustomerFollowupGateway;
use App\Support\DateRange;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

final readonly class CustomerDirectory
{
    public function __construct(
        private BlindIndex $blindIndex,
        private CustomerLabelLocalizer $labels,
        private SensitiveValueMasker $masker,
        private AgentReferenceReader $agents,
        private AgentAccessScopeReader $agentScope,
        private InternalUserReferenceReader $assignableUsers,
        private ReportUserReader $userNames,
        private InstitutionReferenceReader $institutions,
        private CustomerOrderGateway $orders,
        private CustomerFollowupGateway $followups,
        private AuditRecorder $audit,
        private AccessContextResolver $access,
        private BusinessGroupMembershipReader $memberships,
        private BusinessGroupReferenceReader $groups,
        private BusinessClock $clock,
    ) {}

    /**
     * @param  array{search?: string, status_id?: int|null, agent_id?: int|null, institution_id?: int|null, owner_id?: int|null, owner_state?: string, transfer_status?: string, business_group_id?: int|null, created_from?: string, created_to?: string}  $filters
     * @return LengthAwarePaginator<int, array{id: int, code: string, name: string, contact_masked: string, document_masked: string, status: string, source: string, owner_id: int|null, owner: string|null, created_at: string|null}>
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = Customer::query()->with(['primaryContact', 'identityDocument', 'currentStatus']);
        $this->applyScope($query);
        $context = $this->access->current();
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $hash = $this->blindIndex->for($search);
            $contactCustomerIds = $hash === null
                ? []
                : CustomerContact::query()->where('lookup_hash', $hash)->pluck('customer_id')->all();
            $query->where(function ($inner) use ($search, $contactCustomerIds): void {
                $inner->where('name', 'ilike', '%'.$search.'%')
                    ->orWhere('code', 'ilike', '%'.strtoupper($search).'%');
                if ($contactCustomerIds !== []) {
                    $inner->orWhereIn('customers.id', $contactCustomerIds);
                }
            });
        }
        if (($filters['status_id'] ?? null) !== null) {
            $query->where('current_status_id', $filters['status_id']);
        }
        if (($filters['agent_id'] ?? null) !== null) {
            $query->where('source_agent_id', $filters['agent_id']);
        }
        if (($filters['institution_id'] ?? null) !== null) {
            $query->whereKey($this->orders->customerIdsForInstitution((int) $filters['institution_id']));
        }
        if (($filters['owner_id'] ?? null) !== null) {
            $query->where('owner_id', (int) $filters['owner_id']);
        }
        $businessGroupId = ($filters['business_group_id'] ?? null) === null ? null : (int) $filters['business_group_id'];
        if ($businessGroupId !== null) {
            $this->assertBusinessGroupVisible($businessGroupId, $context);
            $agentIds = $this->agentScope->agentIdsForBusinessGroups([$businessGroupId], $this->clock->now()->toDateString());
            if ($agentIds === []) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('source_agent_id', $agentIds);
            }
        }
        if (($filters['owner_state'] ?? null) === 'unassigned') {
            $query->whereNull('owner_id');
        }
        if (($filters['owner_state'] ?? null) === 'invalid') {
            $validOwnerIds = $this->memberships->activeCustomerServiceUserIds(
                $businessGroupId !== null
                    ? [$businessGroupId]
                    : ($context->isSuperAdmin() ? null : $context->businessGroupIds),
                $this->clock->now()->toDateString(),
            );
            $query->whereNotNull('owner_id');
            if ($validOwnerIds !== []) {
                $query->whereNotIn('owner_id', $validOwnerIds);
            }
        }
        if (($filters['transfer_status'] ?? null) === 'pending') {
            $query->whereExists(function ($transferQuery): void {
                $transferQuery->selectRaw('1')
                    ->from('customer_transfer_requests')
                    ->whereColumn('customer_transfer_requests.customer_id', 'customers.id')
                    ->where('customer_transfer_requests.status', 'pending');
            });
        }
        $createdRange = DateRange::fromDates($filters['created_from'] ?? null, $filters['created_to'] ?? null);
        if ($createdRange->startAt !== null) {
            $query->where('created_at', '>=', $createdRange->startAt);
        }
        if ($createdRange->endExclusive !== null) {
            $query->where('created_at', '<', $createdRange->endExclusive);
        }

        if ($context->isCustomerService() && $context->userId !== null) {
            $query->orderByRaw('CASE WHEN owner_id = ? THEN 0 ELSE 1 END', [$context->userId]);
        }
        $page = $query->orderByDesc('customers.created_at')->orderByDesc('customers.id')->paginate($perPage);
        $agentIds = $page->getCollection()->pluck('source_agent_id')->filter()->map(fn ($id): int => (int) $id)->all();
        $agentLabels = $this->agents->agentsByIds($agentIds);
        $ownerIds = $page->getCollection()->pluck('owner_id')->filter()->map(fn ($id): int => (int) $id)->all();
        $ownerLabels = $this->userNames->namesByIds($ownerIds);

        $items = $page->getCollection()->map(function (Customer $customer) use ($agentLabels, $ownerLabels): array {
            return [
                'id' => $customer->id,
                'code' => $customer->code,
                'name' => $customer->name,
                'contact_masked' => $this->masker->mask(data_get($customer->primaryContact, 'value_encrypted')),
                'document_masked' => $this->masker->mask(data_get($customer->identityDocument, 'number_encrypted'), 1, 3),
                'status' => $customer->currentStatus === null
                    ? __('customers.fallback.unset')
                    : $this->labels->status((string) $customer->currentStatus->key, $customer->currentStatus->name),
                'source' => (string) ($agentLabels[(int) $customer->source_agent_id]['name'] ?? __('customers.fallback.unknown_agent')),
                'owner_id' => $customer->owner_id === null ? null : (int) $customer->owner_id,
                'owner' => $customer->owner_id === null ? null : ($ownerLabels[(int) $customer->owner_id] ?? null),
                'created_at' => $customer->created_at?->format('Y-m-d H:i'),
            ];
        });

        // PHPStan treats the inferred array shape as invariant even though it matches the declared shape.
        // @phpstan-ignore return.type
        return new LengthAwarePaginator(
            $items,
            $page->total(),
            $page->perPage(),
            $page->currentPage(),
            ['path' => request()->url(), 'query' => request()->query()],
        );
    }

    private function assertBusinessGroupVisible(int $businessGroupId, AccessContext $context): void
    {
        abort_unless(
            $context->isSuperAdmin()
                ? $this->groups->exists($businessGroupId, true)
                : in_array($businessGroupId, $context->businessGroupIds, true),
            404,
        );
    }

    /** @return array<string, mixed> */
    public function options(): array
    {
        return [
            'agents' => array_values($this->agents->activeAgents()),
            'institutions' => array_values($this->institutions->activeInstitutions()),
            'statuses' => CustomerStatus::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'key', 'name', 'stage_id', 'sort_order'])
                ->map(fn (CustomerStatus $status): array => [
                    'id' => $status->id,
                    'key' => $status->key,
                    'name' => $this->labels->status((string) $status->key, $status->name),
                    'stage_id' => $status->stage_id,
                    'sort_order' => $status->sort_order,
                ])->all(),
            'stages' => CustomerLifecycleStage::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'key', 'name', 'sort_order'])
                ->map(fn (CustomerLifecycleStage $stage): array => [
                    'id' => $stage->id,
                    'key' => $stage->key,
                    'name' => $this->labels->stage((string) $stage->key, $stage->name),
                    'sort_order' => $stage->sort_order,
                ])->all(),
        ];
    }

    /** @return list<array{id: int, name: string}> */
    public function ownerCandidates(): array
    {
        $users = $this->assignableUsers->eligibleUsers();
        $context = $this->access->current();
        $groupIds = $context->isSuperAdmin()
            ? null
            : $context->businessGroupIds;
        $allowedIds = $this->memberships->activeCustomerServiceUserIds($groupIds, $this->clock->now()->toDateString());
        $allowed = array_flip($allowedIds);

        return array_values(array_filter($users, fn (array $user): bool => isset($allowed[(int) $user['id']])));
    }

    /** @return array<string, mixed> */
    public function profile(int $customerId): array
    {
        $customer = Customer::query()->with(['primaryContact', 'identityDocument', 'currentStatus']);
        $this->applyScope($customer);
        $customer = $customer->findOrFail($customerId);
        $canViewSensitive = $this->access->current()->canDownloadSensitiveCustomerData($customer->owner_id === null ? null : (int) $customer->owner_id);

        return [
            'id' => $customer->id,
            'code' => $customer->code,
            'name' => $customer->name,
            'gender' => $customer->gender,
            'birth_date' => $customer->birth_date?->format('Y-m-d'),
            'source_agent_id' => $customer->source_agent_id,
            'source_agent_name' => $this->agents->agentsByIds([(int) $customer->source_agent_id])[(int) $customer->source_agent_id]['name'] ?? null,
            'current_status_id' => $customer->current_status_id,
            'current_status' => $customer->currentStatus === null
                ? null
                : $this->labels->status((string) $customer->currentStatus->key, $customer->currentStatus->name),
            'contact' => $canViewSensitive ? data_get($customer->primaryContact, 'value_encrypted') : null,
            'identity_document' => $canViewSensitive ? data_get($customer->identityDocument, 'number_encrypted') : null,
            'project_intention' => $customer->project_intention,
            'notes' => $customer->notes,
            'owner_id' => $customer->owner_id,
            'owner_name' => $customer->owner_id === null
                ? null
                : ($this->userNames->namesByIds([(int) $customer->owner_id])[(int) $customer->owner_id] ?? null),
            'arrived_at' => $customer->arrived_at?->format('Y-m-d H:i'),
            'created_at' => $customer->created_at?->format('Y-m-d H:i'),
        ];
    }

    /** @return array<string, mixed> */
    public function statusFlow(int $customerId): array
    {
        $customer = Customer::query();
        $this->applyScope($customer);
        $customer = $customer->findOrFail($customerId);
        $currentStatusId = $customer->current_status_id === null ? null : (int) $customer->current_status_id;
        $stages = CustomerLifecycleStage::query()
            ->orderBy('sort_order')
            ->orderBy('key')
            ->get(['id', 'key', 'name', 'sort_order', 'is_active']);
        $statuses = CustomerStatus::query()
            ->orderBy('sort_order')
            ->orderBy('key')
            ->get(['id', 'key', 'name', 'stage_id', 'sort_order', 'is_active']);
        $transitions = CustomerStatusTransition::query()
            ->orderBy('from_status_id')
            ->orderBy('to_status_id')
            ->get(['id', 'from_status_id', 'to_status_id', 'is_active']);
        $history = CustomerStatusHistory::query()
            ->where('customer_id', $customerId)
            ->orderBy('changed_at')
            ->orderBy('id')
            ->get(['from_status_id', 'to_status_id']);
        $visitedStatusIds = $history
            ->flatMap(fn (CustomerStatusHistory $history): array => [$history->from_status_id, $history->to_status_id])
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $visitedTransitionKeys = $history
            ->filter(fn (CustomerStatusHistory $history): bool => $history->from_status_id !== null)
            ->map(fn (CustomerStatusHistory $history): string => $this->statusTransitionKey((int) $history->from_status_id, (int) $history->to_status_id))
            ->unique()
            ->flip();
        $activeStatusIds = $statuses
            ->filter(fn (CustomerStatus $status): bool => (bool) $status->is_active)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        $availableStatusIds = $transitions
            ->filter(fn (CustomerStatusTransition $transition): bool => $currentStatusId !== null
                && $transition->is_active
                && (int) $transition->from_status_id === $currentStatusId
                && in_array((int) $transition->to_status_id, $activeStatusIds, true))
            ->pluck('to_status_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        $currentStatusSortOrder = $currentStatusId === null
            ? null
            : $statuses->firstWhere('id', $currentStatusId)?->sort_order;

        $statusData = $statuses->map(function (CustomerStatus $status) use ($currentStatusId, $visitedStatusIds, $availableStatusIds, $currentStatusSortOrder): array {
            $statusId = (int) $status->id;
            $isCurrent = $statusId === $currentStatusId;
            $isVisited = in_array($statusId, $visitedStatusIds, true);
            $isAvailable = in_array($statusId, $availableStatusIds, true);

            return [
                'id' => $statusId,
                'key' => (string) $status->key,
                'name' => $this->labels->status((string) $status->key, $status->name),
                'stage_id' => (int) $status->stage_id,
                'sort_order' => (int) $status->sort_order,
                'is_active' => (bool) $status->is_active,
                'is_visited' => $isVisited,
                'is_current' => $isCurrent,
                'is_available' => $isAvailable,
                'state' => $this->statusFlowState((bool) $status->is_active, $isCurrent, $isAvailable, $currentStatusSortOrder, (int) $status->sort_order),
            ];
        })->values();
        $statusesByStage = $statusData->groupBy('stage_id');
        $statusNames = $statusData->mapWithKeys(fn (array $status): array => [$status['id'] => $status['name']])->all();
        $statusStageIds = $statusData->mapWithKeys(fn (array $status): array => [$status['id'] => $status['stage_id']])->all();
        $stageIds = $stages->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
        $adjacentStagePairs = [];
        foreach ($stageIds as $index => $fromStageId) {
            if (isset($stageIds[$index + 1])) {
                $adjacentStagePairs[] = [$fromStageId, $stageIds[$index + 1]];
            }
        }

        return [
            'current_status_id' => $currentStatusId,
            'adjacent_stage_pairs' => $adjacentStagePairs,
            'stages' => $stages->map(function (CustomerLifecycleStage $stage) use ($statusesByStage): array {
                $stageStatuses = $statusesByStage->get((int) $stage->id, collect())->values();
                $hasCurrentStatus = $stageStatuses->contains(fn (array $status): bool => (bool) $status['is_current']);

                return [
                    'id' => (int) $stage->id,
                    'key' => (string) $stage->key,
                    'name' => $this->labels->stage((string) $stage->key, $stage->name),
                    'sort_order' => (int) $stage->sort_order,
                    'is_active' => (bool) $stage->is_active,
                    'state' => ! $stage->is_active ? 'inactive' : ($hasCurrentStatus ? 'current' : 'active'),
                    'statuses' => $stageStatuses->all(),
                ];
            })->values()->all(),
            'statuses' => $statusData->all(),
            'transitions' => $transitions->map(function (CustomerStatusTransition $transition) use ($currentStatusId, $activeStatusIds, $visitedTransitionKeys, $statusNames, $statusStageIds): array {
                $fromStatusId = (int) $transition->from_status_id;
                $toStatusId = (int) $transition->to_status_id;

                return [
                    'id' => (int) $transition->id,
                    'from' => $fromStatusId,
                    'to' => $toStatusId,
                    'from_status_id' => $fromStatusId,
                    'to_status_id' => $toStatusId,
                    'from_status_name' => $statusNames[$fromStatusId] ?? __('customers.fallback.unknown_status'),
                    'to_status_name' => $statusNames[$toStatusId] ?? __('customers.fallback.unknown_status'),
                    'from_stage_id' => $statusStageIds[$fromStatusId] ?? null,
                    'to_stage_id' => $statusStageIds[$toStatusId] ?? null,
                    'is_active' => (bool) $transition->is_active,
                    'visited' => $visitedTransitionKeys->has($this->statusTransitionKey($fromStatusId, $toStatusId)),
                    'is_available' => $currentStatusId !== null
                        && (bool) $transition->is_active
                        && $fromStatusId === $currentStatusId
                        && in_array($toStatusId, $activeStatusIds, true),
                ];
            })->values()->all(),
            'available_next_status_ids' => array_values($availableStatusIds),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function timeline(int $customerId, ?string $type = null): array
    {
        $customer = Customer::query();
        $this->applyScope($customer);
        $customer = $customer->findOrFail($customerId);
        /** @var array<int, array<string, mixed>> $events */
        $events = [
            ...$this->orders->timelineForCustomer($customerId),
            ...$this->followups->timelineForCustomer($customerId),
        ];

        $statusIds = CustomerStatusHistory::query()
            ->where('customer_id', $customerId)
            ->get()
            ->flatMap(fn (CustomerStatusHistory $history): array => [$history->from_status_id, $history->to_status_id])
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->all();
        $statuses = CustomerStatus::query()->whereKey($statusIds)->get(['id', 'key', 'name'])
            ->mapWithKeys(fn (CustomerStatus $status): array => [
                $status->id => $this->labels->status((string) $status->key, $status->name),
            ]);
        foreach (CustomerStatusHistory::query()->where('customer_id', $customerId)->get() as $history) {
            $events[] = [
                'type' => 'status',
                'occurred_at' => $history->changed_at->toIso8601String(),
                'title' => __('customers.timeline.status_changed'),
                'content' => ($history->from_status_id === null
                    ? __('customers.timeline.registered')
                    : ($statuses[$history->from_status_id] ?? __('customers.fallback.unknown_status')))
                    .' → '.($statuses[$history->to_status_id] ?? __('customers.fallback.unknown_status'))
                    .'（'.($history->from_status_id === null ? __('customers.timeline.customer_registered') : $history->reason).'）',
                'owner_id' => $history->changed_by,
                'meta' => [],
            ];
        }
        foreach (CustomerOwnerHistory::query()->where('customer_id', $customerId)->orderBy('effective_at')->orderBy('id')->get() as $history) {
            $events[] = [
                'type' => 'owner',
                'occurred_at' => $history->effective_at->toIso8601String(),
                'title' => __('customers.timeline.owner_changed'),
                'content' => __('customers.timeline.owner_changed_detail', ['reason' => $history->reason]),
                'owner_id' => $history->changed_by,
                'meta' => [
                    'from_owner_id' => $history->from_owner_id,
                    'to_owner_id' => $history->to_owner_id,
                    'source' => $history->source,
                ],
            ];
        }
        foreach ($this->audit->trail($customer, 'customer') as $entry) {
            if ($entry->event === 'status_changed') {
                continue;
            }
            $events[] = [
                'type' => $entry->event === 'created' ? 'created' : 'profile',
                'occurred_at' => $entry->occurredAt->toIso8601String(),
                'title' => $entry->description,
                'content' => $entry->event === 'updated' ? __('customers.timeline.profile_updated') : __('customers.timeline.customer_profile_created'),
                'owner_id' => $entry->causerId,
                'meta' => $entry->properties,
            ];
        }
        $hasCreatedEvent = false;
        foreach ($events as $event) {
            if (($event['type'] ?? null) === 'created') {
                $hasCreatedEvent = true;
                break;
            }
        }
        if (! $hasCreatedEvent) {
            $events[] = [
                'type' => 'created',
                'occurred_at' => $customer->created_at?->toIso8601String(),
                'title' => __('customers.timeline.customer_registered'),
                'content' => __('customers.timeline.customer_profile_created'),
                'owner_id' => $customer->owner_id,
                'meta' => [],
            ];
        }

        $institutionIds = [];
        $ownerIds = [];
        foreach ($events as $event) {
            if (isset($event['institution_id'])) {
                $institutionIds[] = (int) $event['institution_id'];
            }
            if (isset($event['owner_id'])) {
                $ownerIds[] = (int) $event['owner_id'];
            }
        }
        $institutionLabels = $this->institutions->institutionsByIds($institutionIds);
        $owners = $this->userNames->namesByIds($ownerIds);

        /** @var array<int, array<string, mixed>> $result */
        $result = [];
        foreach ($events as $event) {
            if ($type !== null && $type !== '' && $event['type'] !== $type) {
                continue;
            }
            $event['institution'] = isset($event['institution_id'])
                ? ($institutionLabels[(int) $event['institution_id']]['name'] ?? null)
                : null;
            $event['owner'] = isset($event['owner_id']) ? ($owners[$event['owner_id']] ?? null) : null;
            $result[] = $event;
        }
        usort(
            $result,
            fn (array $left, array $right): int => CarbonImmutable::parse($right['occurred_at'] ?? '1970-01-01')
                ->timestamp <=> CarbonImmutable::parse($left['occurred_at'] ?? '1970-01-01')->timestamp,
        );

        return $result;
    }

    private function statusFlowState(bool $isActive, bool $isCurrent, bool $isAvailable, ?int $currentSortOrder, int $statusSortOrder): string
    {
        if ($isCurrent) {
            return $isActive ? 'current' : 'current_inactive';
        }

        if (! $isActive) {
            return 'inactive';
        }

        if ($currentSortOrder !== null && $statusSortOrder < $currentSortOrder) {
            return 'completed';
        }

        return $isAvailable ? 'available' : 'unavailable';
    }

    /** @param Builder<Customer> $query */
    private function applyScope(Builder $query): void
    {
        $context = $this->access->current();
        if ($context->isSuperAdmin()) {
            return;
        }

        if (! $context->hasEffectiveBusinessScope()) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function ($scope) use ($context): void {
            if ($context->userId !== null) {
                $scope->where('owner_id', $context->userId);
            }
            if ($context->agentIds !== []) {
                $scope->orWhereIn('source_agent_id', $context->agentIds);
            }
        });
    }

    private function statusTransitionKey(int $fromStatusId, int $toStatusId): string
    {
        return $fromStatusId.':'.$toStatusId;
    }
}
