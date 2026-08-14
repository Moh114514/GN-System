<?php

namespace App\Modules\Customer\Application\Services;

use App\Models\User;
use App\Modules\Agent\Application\Contracts\AgentReferenceReader;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Config\Application\Contracts\InstitutionReferenceReader;
use App\Modules\Customer\Domain\BlindIndex;
use App\Modules\Customer\Domain\CustomerLabelLocalizer;
use App\Modules\Customer\Domain\SensitiveValueMasker;
use App\Modules\Customer\Infrastructure\Models\Customer;
use App\Modules\Customer\Infrastructure\Models\CustomerContact;
use App\Modules\Customer\Infrastructure\Models\CustomerLifecycleStage;
use App\Modules\Customer\Infrastructure\Models\CustomerStatus;
use App\Modules\Customer\Infrastructure\Models\CustomerStatusHistory;
use App\Modules\Customer\Infrastructure\Models\CustomerStatusTransition;
use App\Modules\Order\Application\Contracts\CustomerOrderGateway;
use App\Modules\Reminder\Application\Contracts\CustomerFollowupGateway;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;

final readonly class CustomerDirectory
{
    public function __construct(
        private BlindIndex $blindIndex,
        private CustomerLabelLocalizer $labels,
        private SensitiveValueMasker $masker,
        private AgentReferenceReader $agents,
        private InstitutionReferenceReader $institutions,
        private CustomerOrderGateway $orders,
        private CustomerFollowupGateway $followups,
        private AuditRecorder $audit,
    ) {}

    /**
     * @param  array{search?: string, status_id?: int|null, agent_id?: int|null, institution_id?: int|null}  $filters
     * @return LengthAwarePaginator<int, array{id: int, code: string, name: string, contact_masked: string, document_masked: string, status: string, source: string, created_at: string|null}>
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = Customer::query()->with(['primaryContact', 'identityDocument', 'currentStatus']);
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

        $page = $query->latest('created_at')->paginate($perPage);
        $agentIds = $page->getCollection()->pluck('source_agent_id')->filter()->map(fn ($id): int => (int) $id)->all();
        $agentLabels = $this->agents->agentsByIds($agentIds);

        $items = $page->getCollection()->map(function (Customer $customer) use ($agentLabels): array {
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

    /** @return array<string, mixed> */
    public function profile(int $customerId): array
    {
        $customer = Customer::query()->with(['primaryContact', 'identityDocument', 'currentStatus'])->findOrFail($customerId);

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
            'contact' => data_get($customer->primaryContact, 'value_encrypted'),
            'identity_document' => data_get($customer->identityDocument, 'number_encrypted'),
            'project_intention' => $customer->project_intention,
            'notes' => $customer->notes,
            'owner_id' => $customer->owner_id,
            'created_at' => $customer->created_at?->format('Y-m-d H:i'),
        ];
    }

    /** @return array<string, mixed> */
    public function statusGraph(int $customerId): array
    {
        $customer = Customer::query()->findOrFail($customerId);
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
        $visitedStatusIds = CustomerStatusHistory::query()
            ->where('customer_id', $customerId)
            ->get(['from_status_id', 'to_status_id'])
            ->flatMap(fn (CustomerStatusHistory $history): array => [$history->from_status_id, $history->to_status_id])
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
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

        $statusData = $statuses->map(function (CustomerStatus $status) use ($currentStatusId, $visitedStatusIds, $availableStatusIds): array {
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
                'state' => $this->statusGraphState((bool) $status->is_active, $isCurrent, $isVisited, $isAvailable),
            ];
        })->values();
        $statusesByStage = $statusData->groupBy('stage_id');

        return [
            'current_status_id' => $currentStatusId,
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
            'transitions' => $transitions->map(fn (CustomerStatusTransition $transition): array => [
                'id' => (int) $transition->id,
                'from_status_id' => (int) $transition->from_status_id,
                'to_status_id' => (int) $transition->to_status_id,
                'is_active' => (bool) $transition->is_active,
                'is_available' => $currentStatusId !== null
                    && (bool) $transition->is_active
                    && (int) $transition->from_status_id === $currentStatusId
                    && in_array((int) $transition->to_status_id, $activeStatusIds, true),
            ])->values()->all(),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function timeline(int $customerId, ?string $type = null): array
    {
        $customer = Customer::query()->findOrFail($customerId);
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
        $owners = User::query()->whereKey($ownerIds)->pluck('name', 'id');

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

    private function statusGraphState(bool $isActive, bool $isCurrent, bool $isVisited, bool $isAvailable): string
    {
        if ($isCurrent) {
            return $isActive ? 'current' : 'current_inactive';
        }

        if (! $isActive) {
            return 'inactive';
        }

        if ($isVisited) {
            return 'completed';
        }

        return $isAvailable ? 'available' : 'unavailable';
    }
}
