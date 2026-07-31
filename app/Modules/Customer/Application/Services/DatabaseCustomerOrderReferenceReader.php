<?php

namespace App\Modules\Customer\Application\Services;

use App\Modules\Customer\Application\Contracts\CustomerOrderReferenceReader;
use App\Modules\Customer\Infrastructure\Models\Customer;
use App\Modules\Customer\Infrastructure\Models\DirectSalesSource;

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
            ->get(['id', 'code', 'name', 'original_channel', 'source_agent_id', 'source_direct_sales_id'])
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
            ->get(['id', 'code', 'name', 'original_channel', 'source_agent_id', 'source_direct_sales_id'])
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

    public function activeDirectSalesSources(): array
    {
        return DirectSalesSource::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'code', 'name'])
            ->map(fn (DirectSalesSource $source): array => [
                'id' => (int) $source->id,
                'code' => (string) $source->code,
                'name' => (string) $source->name,
            ])
            ->all();
    }

    public function directSalesSourcesByIds(array $ids): array
    {
        return DirectSalesSource::query()
            ->whereKey(array_values(array_unique($ids)))
            ->get(['id', 'code', 'name'])
            ->mapWithKeys(fn (DirectSalesSource $source): array => [
                (int) $source->id => [
                    'id' => (int) $source->id,
                    'code' => (string) $source->code,
                    'name' => (string) $source->name,
                ],
            ])
            ->all();
    }

    /** @return array{id: int, code: string, name: string, original_channel: string, source_agent_id: int|null, source_direct_sales_id: int|null} */
    private function serializeCustomer(Customer $customer): array
    {
        return [
            'id' => (int) $customer->id,
            'code' => (string) $customer->code,
            'name' => (string) $customer->name,
            'original_channel' => (string) $customer->original_channel,
            'source_agent_id' => $customer->source_agent_id === null ? null : (int) $customer->source_agent_id,
            'source_direct_sales_id' => $customer->source_direct_sales_id === null ? null : (int) $customer->source_direct_sales_id,
        ];
    }
}
