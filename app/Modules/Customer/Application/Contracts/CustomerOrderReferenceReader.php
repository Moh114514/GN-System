<?php

namespace App\Modules\Customer\Application\Contracts;

interface CustomerOrderReferenceReader
{
    /** @return array{id: int, code: string, name: string, original_channel: string, source_agent_id: int|null, source_direct_sales_id: int|null} */
    public function customerForOrder(int $customerId): array;

    /** @return array<int, array{id: int, code: string, name: string}> */
    public function activeDirectSalesSources(): array;
}
