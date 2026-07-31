<?php

namespace App\Modules\Customer\Application\Contracts;

interface CustomerOrderReferenceReader
{
    /** @return array{id: int, code: string, name: string, original_channel: string, source_agent_id: int|null, source_direct_sales_id: int|null} */
    public function customerForOrder(int $customerId): array;

    /**
     * @param  array<int, int>  $ids
     * @return array<int, array{id: int, code: string, name: string, original_channel: string, source_agent_id: int|null, source_direct_sales_id: int|null}>
     */
    public function customersForOrders(array $ids): array;

    /** @return array<int, array{id: int, code: string, name: string, original_channel: string, source_agent_id: int|null, source_direct_sales_id: int|null}> */
    public function searchCustomersForOrder(string $search, int $limit = 20): array;

    /** @return array<int, int> */
    public function customerIdsForOrderSearch(string $search): array;

    /** @return array<int, array{id: int, code: string, name: string}> */
    public function activeDirectSalesSources(): array;

    /**
     * @param  array<int, int>  $ids
     * @return array<int, array{id: int, code: string, name: string}>
     */
    public function directSalesSourcesByIds(array $ids): array;
}
