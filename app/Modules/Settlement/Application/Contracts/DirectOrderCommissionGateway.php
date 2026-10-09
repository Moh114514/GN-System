<?php

namespace App\Modules\Settlement\Application\Contracts;

use App\Modules\Settlement\Application\Data\DirectOrderCommissionData;

interface DirectOrderCommissionGateway
{
    public function recordForCompletedOrder(DirectOrderCommissionData $data): int;

    public function voidForOrder(int $orderId, int $actorId, string $reason): void;

    /** @return list<array<string, mixed>> */
    public function recordsForViewer(): array;
}
