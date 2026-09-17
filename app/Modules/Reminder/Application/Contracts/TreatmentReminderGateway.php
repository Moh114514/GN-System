<?php

namespace App\Modules\Reminder\Application\Contracts;

use App\Modules\Reminder\Application\Data\CompletedTreatmentData;
use App\Modules\Reminder\Application\Data\CustomerTreatmentCompletedData;

interface TreatmentReminderGateway
{
    public function schedule(CompletedTreatmentData $data): void;

    public function scheduleForCustomer(CustomerTreatmentCompletedData $data): void;

    public function cancelForOrder(int $orderId, int $actorId, string $reason): void;

    public function transferForCustomer(int $customerId, int $ownerId, int $actorId): int;
}
