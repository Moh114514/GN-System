<?php

namespace App\Modules\Reminder\Application\Contracts;

use App\Modules\Reminder\Application\Data\CompletedTreatmentData;

interface TreatmentReminderGateway
{
    public function schedule(CompletedTreatmentData $data): void;

    public function cancelForOrder(int $orderId, int $actorId, string $reason): void;
}
