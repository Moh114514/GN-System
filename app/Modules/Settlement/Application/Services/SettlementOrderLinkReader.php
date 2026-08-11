<?php

namespace App\Modules\Settlement\Application\Services;

use App\Modules\Order\Application\Contracts\SettlementOrderReader;

final readonly class SettlementOrderLinkReader
{
    public function __construct(private SettlementOrderReader $orders) {}

    /**
     * @param  array<int, int>  $orderIds
     * @return array<int, int>
     */
    public function existingOrderIds(array $orderIds): array
    {
        return $this->orders->existingOrderIds($orderIds);
    }
}
