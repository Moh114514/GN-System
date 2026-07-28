<?php

namespace App\Modules\Order\Application\Contracts;

use App\Modules\Order\Application\Data\DailyOrderData;
use App\Modules\Order\Application\Data\OrderSummaryData;
use Carbon\CarbonImmutable;

interface DailyOrderGateway
{
    public function create(DailyOrderData $data): int;

    public function complete(int $orderId, CarbonImmutable $completedOn, int $actorId, ?string $ipAddress): int;

    /** @return array<int, OrderSummaryData> */
    public function forCustomer(int $customerId): array;

    /** @return array<int, OrderSummaryData> */
    public function forAgent(int $agentId): array;
}
