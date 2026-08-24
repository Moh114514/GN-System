<?php

namespace App\Modules\Settlement\Application\Contracts;

use App\Modules\Settlement\Application\Data\BdCommissionOrderData;

interface BdCommissionCorrectionGateway
{
    public function onOrderCorrected(
        BdCommissionOrderData $before,
        ?BdCommissionOrderData $after,
        int $actorId,
        ?string $ipAddress,
    ): void;
}
