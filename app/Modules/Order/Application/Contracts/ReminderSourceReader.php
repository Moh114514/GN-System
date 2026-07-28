<?php

namespace App\Modules\Order\Application\Contracts;

use App\Modules\Order\Application\Data\ReminderAppointmentData;
use App\Modules\Order\Application\Data\ReminderOrderSourceData;

interface ReminderSourceReader
{
    /** @return array<int, ReminderAppointmentData> */
    public function appointments(): array;

    /** @return array<int, ReminderOrderSourceData> */
    public function completedOrders(): array;
}
