<?php

namespace App\Modules\Settlement\Application\Contracts;

use App\Modules\Settlement\Application\Data\PaidOrderCorrectionData;

interface PaidOrderCorrectionGateway
{
    public function record(PaidOrderCorrectionData $data): void;
}
