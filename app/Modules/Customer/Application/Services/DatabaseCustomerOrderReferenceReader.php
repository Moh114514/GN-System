<?php

namespace App\Modules\Customer\Application\Services;

use App\Modules\Auth\Application\Contracts\AccessContextResolver;
use App\Modules\Customer\Application\Contracts\CustomerOrderReferenceReader;
use App\Modules\Customer\Infrastructure\Models\Customer;
use Illuminate\Database\Eloquent\Builder;

final class DatabaseCustomerOrderReferenceReader implements CustomerOrderReferenceReader
{
    public function __construct(private readonly AccessContextResolver $access) {}

    public function customerForOrder(int $customerId): array
    {
        $customer = $this->scoped()->findOrFail($customerId);

        return $this->serializeCustomer($customer);
    }

    public function customersForOrders(array $ids): array
    {
        return $this->scoped()
            ->whereKey(array_values(array_unique($ids)))
            ->get(['id', 'code', 'name', 'source_agent_id', 'owner_id'])
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
            ->get(['id', 'code', 'name', 'source_agent_id', 'owner_id'])
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

    /** @return array{id: int, code: string, name: string, source_agent_id: int, owner_id: int|null} */
    private function serializeCustomer(Customer $customer): array
    {
        return [
            'id' => (int) $customer->id,
            'code' => (string) $customer->code,
            'name' => (string) $customer->name,
            'source_agent_id' => (int) $customer->source_agent_id,
            'owner_id' => $customer->owner_id === null ? null : (int) $customer->owner_id,
        ];
    }

    /** @return Builder<Customer> */
    private function scoped(): Builder
    {
        $context = $this->access->current();
        if ($context->isSuperAdmin()) {
            return Customer::query();
        }

        if (! $context->hasEffectiveBusinessScope()) {
            return Customer::query()->whereRaw('1 = 0');
        }

        return Customer::query()->where(function ($query) use ($context): void {
            if ($context->userId !== null) {
                $query->where('owner_id', $context->userId);
            }
            if ($context->agentIds !== []) {
                $query->orWhereIn('source_agent_id', $context->agentIds);
            }
        });
    }
}
