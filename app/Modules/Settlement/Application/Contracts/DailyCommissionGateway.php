<?php

namespace App\Modules\Settlement\Application\Contracts;

use App\Modules\Settlement\Application\Data\CompletedOrderCommissionData;

interface DailyCommissionGateway
{
    public function recordForCompletedOrder(CompletedOrderCommissionData $data): int;

    public function rollbackForOrder(int $orderId): void;
}
