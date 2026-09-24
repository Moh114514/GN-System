<?php

namespace App\Modules\Customer\Application\Contracts;

interface CustomerOrderReferenceReader
{
    /** @return array{id: int, code: string, name: string, source_type: string, source_agent_id: int|null, direct_channel_id: int|null, direct_channel_name: string|null, owner_id: int|null, current_status_key: string|null, current_status: string|null, arrived_at: string|null} */
    public function customerForOrder(int $customerId): array;

    /**
     * @param  array<int, int>  $ids
     * @return array<int, array{id: int, code: string, name: string, source_type: string, source_agent_id: int|null, direct_channel_id: int|null, direct_channel_name: string|null, owner_id: int|null, current_status_key: string|null, current_status: string|null, arrived_at: string|null}>
     */
    public function customersForOrders(array $ids): array;

    /** @return array<int, array{id: int, code: string, name: string, source_type: string, source_agent_id: int|null, direct_channel_id: int|null, direct_channel_name: string|null, owner_id: int|null, current_status_key: string|null, current_status: string|null, arrived_at: string|null}>
     */
    public function searchCustomersForOrder(string $search, int $limit = 20): array;

    /** @return array<int, int> */
    public function customerIdsForOrderSearch(string $search): array;

    /** @return array<int, int> IDs of direct customers currently owned by the given user */
    public function directCustomerIdsForOwner(int $ownerId): array;

    /** @return array<int, int> Agent IDs on agent-source customers currently owned by the given user */
    public function agentIdsForOwner(int $ownerId): array;
}
