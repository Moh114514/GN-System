<?php

namespace App\Modules\Customer\Application\Services;

use App\Modules\Customer\Application\Contracts\CustomerOrderReferenceReader;
use App\Modules\Customer\Infrastructure\Models\Customer;

final class DatabaseCustomerOrderReferenceReader implements CustomerOrderReferenceReader
{
    public function customerForOrder(int $customerId): array
    {
        $customer = Customer::query()->findOrFail($customerId);

        return $this->serializeCustomer($customer);
    }

    public function customersForOrders(array $ids): array
    {
        return Customer::query()
            ->whereKey(array_values(array_unique($ids)))
            ->get(['id', 'code', 'name', 'source_agent_id'])
            ->mapWithKeys(fn (Customer $customer): array => [
                (int) $customer->id => $this->serializeCustomer($customer),
            ])
            ->all();
    }

    public function searchCustomersForOrder(string $search, int $limit = 20): array
    {
        $query = Customer::query();
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
            ->get(['id', 'code', 'name', 'source_agent_id'])
            ->map(fn (Customer $customer): array => $this->serializeCustomer($customer))
            ->all();
    }

    public function customerIdsForOrderSearch(string $search): array
    {
        $search = trim($search);
        if ($search === '') {
            return [];
        }

        return Customer::query()
            ->where(function ($query) use ($search): void {
                $query->where('name', 'ilike', '%'.$search.'%')
                    ->orWhere('code', 'ilike', '%'.strtoupper($search).'%');
            })
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /** @return array{id: int, code: string, name: string, source_agent_id: int} */
    private function serializeCustomer(Customer $customer): array
    {
        return [
            'id' => (int) $customer->id,
            'code' => (string) $customer->code,
            'name' => (string) $customer->name,
            'source_agent_id' => (int) $customer->source_agent_id,
        ];
    }
}
