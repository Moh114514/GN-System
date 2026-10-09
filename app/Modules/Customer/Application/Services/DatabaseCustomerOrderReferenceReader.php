<?php

namespace App\Modules\Customer\Application\Services;

use App\Modules\Auth\Application\Contracts\AccessContextResolver;
use App\Modules\Customer\Application\Contracts\CustomerOrderReferenceReader;
use App\Modules\Customer\Domain\CustomerLabelLocalizer;
use App\Modules\Customer\Infrastructure\Models\Customer;
use Illuminate\Database\Eloquent\Builder;

final class DatabaseCustomerOrderReferenceReader implements CustomerOrderReferenceReader
{
    public function __construct(
        private readonly AccessContextResolver $access,
        private readonly CustomerLabelLocalizer $labels,
    ) {}

    public function customerForOrder(int $customerId): array
    {
        $customer = $this->scoped()->with(['currentStatus', 'directChannel'])->findOrFail($customerId);

        return $this->serializeCustomer($customer);
    }

    public function customersForOrders(array $ids): array
    {
        return $this->scoped()
            ->with(['currentStatus', 'directChannel'])
            ->whereKey(array_values(array_unique($ids)))
            ->get(['id', 'code', 'name', 'source_type', 'source_agent_id', 'direct_channel_id', 'owner_id', 'current_status_id', 'arrived_at'])
            ->mapWithKeys(fn (Customer $customer): array => [
                (int) $customer->id => $this->serializeCustomer($customer),
            ])
            ->all();
    }

    public function searchCustomersForOrder(string $search, int $limit = 20): array
    {
        $query = $this->scoped();
        $search = trim($search);
        if ($search !== '') {
            $query->where(function ($inner) use ($search): void {
                $inner->where('name', 'ilike', '%'.$search.'%')
                    ->orWhere('code', 'ilike', '%'.strtoupper($search).'%');
            });
        }

        return $query
            ->latest('updated_at')
            ->limit(max(1, min($limit, 50)))
            ->with(['currentStatus', 'directChannel'])
            ->get(['id', 'code', 'name', 'source_type', 'source_agent_id', 'direct_channel_id', 'owner_id', 'current_status_id', 'arrived_at'])
            ->map(fn (Customer $customer): array => $this->serializeCustomer($customer))
            ->all();
    }

    public function customerIdsForOrderSearch(string $search): array
    {
        $search = trim($search);
        if ($search === '') {
            return [];
        }

        return $this->scoped()
            ->where(function ($query) use ($search): void {
                $query->where('name', 'ilike', '%'.$search.'%')
                    ->orWhere('code', 'ilike', '%'.strtoupper($search).'%');
            })
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    public function directCustomerIdsForOwner(int $ownerId): array
    {
        return Customer::query()
            ->where('source_type', 'direct')
            ->where('owner_id', $ownerId)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    public function agentIdsForOwner(int $ownerId): array
    {
        return Customer::query()
            ->where('source_type', 'agent')
            ->where('owner_id', $ownerId)
            ->whereNotNull('source_agent_id')
            ->distinct()
            ->orderBy('source_agent_id')
            ->pluck('source_agent_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /** @return array{id: int, code: string, name: string, source_type: string, source_agent_id: int|null, direct_channel_id: int|null, direct_channel_name: string|null, owner_id: int|null, current_status_key: string|null, current_status: string|null, arrived_at: string|null} */
    private function serializeCustomer(Customer $customer): array
    {
        return [
            'id' => (int) $customer->id,
            'code' => (string) $customer->code,
            'name' => (string) $customer->name,
            'source_type' => (string) $customer->source_type,
            'source_agent_id' => $customer->source_agent_id === null ? null : (int) $customer->source_agent_id,
            'direct_channel_id' => $customer->direct_channel_id === null ? null : (int) $customer->direct_channel_id,
            'direct_channel_name' => $customer->directChannel?->name,
            'owner_id' => $customer->owner_id === null ? null : (int) $customer->owner_id,
            'current_status_key' => $customer->currentStatus?->key,
            'current_status' => $customer->currentStatus === null
                ? null
                : $this->labels->status((string) $customer->currentStatus->key, (string) $customer->currentStatus->name),
            'arrived_at' => $customer->arrived_at?->format('Y-m-d H:i'),
        ];
    }

    /** @return Builder<Customer> */
    private function scoped(): Builder
    {
        $context = $this->access->current();
        if ($context->isSuperAdmin()) {
            return Customer::query();
        }

        if ($context->isDirectCustomerManager()) {
            return Customer::query()->where('source_type', 'direct')->where('owner_id', $context->userId);
        }

        if ($context->isCustomerService()) {
            return Customer::query()->where('owner_id', $context->userId);
        }

        if (! $context->isBdManager() || $context->agentIds === []) {
            return Customer::query()->whereRaw('1 = 0');
        }

        return Customer::query()->where('source_type', 'agent')->whereIn('source_agent_id', $context->agentIds);
    }
}
