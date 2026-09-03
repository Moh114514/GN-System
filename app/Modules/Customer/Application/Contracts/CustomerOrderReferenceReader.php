<?php

namespace App\Modules\Customer\Application\Contracts;

interface CustomerOrderReferenceReader
{
    /** @return array{id: int, code: string, name: string, source_agent_id: int, owner_id: int|null, current_status_key: string|null, current_status: string|null, arrived_at: string|null} */
    public function customerForOrder(int $customerId): array;

    /**
     * @param  array<int, int>  $ids
     * @return array<int, array{id: int, code: string, name: string, source_agent_id: int, owner_id: int|null, current_status_key: string|null, current_status: string|null, arrived_at: string|null}>
     */
    public function customersForOrders(array $ids): array;

    /** @return array<int, array{id: int, code: string, name: string, source_agent_id: int, owner_id: int|null, current_status_key: string|null, current_status: string|null, arrived_at: string|null}> */
    public function searchCustomersForOrder(string $search, int $limit = 20): array;

    /** @return array<int, int> */
    public function customerIdsForOrderSearch(string $search): array;
}
