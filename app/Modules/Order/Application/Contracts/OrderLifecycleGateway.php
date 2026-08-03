<?php

namespace App\Modules\Order\Application\Contracts;

use App\Modules\Order\Application\Data\OrderUpdateData;

interface OrderLifecycleGateway
{
    public function updatePending(OrderUpdateData $data, int $actorId, ?string $ipAddress): int;

    public function cancel(int $orderId, int $actorId, string $reason, ?string $ipAddress): int;

    public function reopen(int $orderId, int $actorId, string $reason, ?string $ipAddress): int;

    public function rollbackCompleted(int $orderId, int $actorId, string $reason, ?string $ipAddress): int;

    public function softDelete(int $orderId, int $actorId, string $reason, ?string $ipAddress): int;

    public function restore(int $orderId, int $actorId, ?string $ipAddress): int;
}
