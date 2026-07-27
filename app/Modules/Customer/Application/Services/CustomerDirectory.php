<?php

namespace App\Modules\Customer\Application\Services;

use App\Models\User;
use App\Modules\Agent\Application\Contracts\AgentReferenceReader;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Config\Application\Contracts\InstitutionReferenceReader;
use App\Modules\Customer\Domain\BlindIndex;
use App\Modules\Customer\Domain\SensitiveValueMasker;
use App\Modules\Customer\Infrastructure\Models\Customer;
use App\Modules\Customer\Infrastructure\Models\CustomerContact;
use App\Modules\Customer\Infrastructure\Models\CustomerLifecycleStage;
use App\Modules\Customer\Infrastructure\Models\CustomerStatus;
use App\Modules\Customer\Infrastructure\Models\CustomerStatusHistory;
use App\Modules\Customer\Infrastructure\Models\DirectSalesSource;
use App\Modules\Order\Application\Contracts\CustomerOrderGateway;
use App\Modules\Reminder\Application\Contracts\CustomerFollowupGateway;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;

final readonly class CustomerDirectory
{
    public function __construct(
        private BlindIndex $blindIndex,
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
        $directLabels = DirectSalesSource::query()
            ->whereKey($page->getCollection()->pluck('source_direct_sales_id')->filter()->all())
            ->get()
            ->keyBy('id');

        $items = $page->getCollection()->map(fn (Customer $customer): array => [
            'id' => $customer->id,
            'code' => $customer->code,
            'name' => $customer->name,
            'contact_masked' => $this->masker->mask(data_get($customer->primaryContact, 'value_encrypted')),
            'document_masked' => $this->masker->mask(data_get($customer->identityDocument, 'number_encrypted'), 1, 3),
            'status' => (string) data_get($customer->currentStatus, 'name', '未设置'),
            'source' => $customer->original_channel === 'agent'
                ? (string) ($agentLabels[(int) $customer->source_agent_id]['name'] ?? '未知代理商')
                : (string) data_get($directLabels->get($customer->source_direct_sales_id), 'name', '未知直销来源'),
            'created_at' => $customer->created_at?->format('Y-m-d H:i'),
        ]);

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
            'direct_sources' => DirectSalesSource::query()->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name'])->toArray(),
            'institutions' => array_values($this->institutions->activeInstitutions()),
            'statuses' => CustomerStatus::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'key', 'name', 'stage_id', 'sort_order'])->toArray(),
            'stages' => CustomerLifecycleStage::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'key', 'name', 'sort_order'])->toArray(),
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
            'original_channel' => $customer->original_channel,
            'source_agent_id' => $customer->source_agent_id,
            'source_direct_sales_id' => $customer->source_direct_sales_id,
            'current_status_id' => $customer->current_status_id,
            'current_status' => data_get($customer->currentStatus, 'name'),
            'contact' => data_get($customer->primaryContact, 'value_encrypted'),
            'identity_document' => data_get($customer->identityDocument, 'number_encrypted'),
            'project_intention' => $customer->project_intention,
            'notes' => $customer->notes,
            'owner_id' => $customer->owner_id,
            'created_at' => $customer->created_at?->format('Y-m-d H:i'),
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
        $statuses = CustomerStatus::query()->whereKey($statusIds)->pluck('name', 'id');
        foreach (CustomerStatusHistory::query()->where('customer_id', $customerId)->get() as $history) {
            $events[] = [
                'type' => 'status',
                'occurred_at' => $history->changed_at->toIso8601String(),
                'title' => '状态变更',
                'content' => ($history->from_status_id === null ? '建档' : ($statuses[$history->from_status_id] ?? '未知状态'))
                    .' → '.($statuses[$history->to_status_id] ?? '未知状态')
                    .'；'.$history->reason,
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
                'content' => $entry->event === 'updated' ? '客户资料已更新并留痕' : '客户档案创建',
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
                'title' => '客户建档',
                'content' => '客户档案已建立',
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
}
