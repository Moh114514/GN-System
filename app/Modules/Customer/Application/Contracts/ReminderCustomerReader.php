<?php

namespace App\Modules\Customer\Application\Contracts;

use App\Modules\Customer\Application\Data\ReminderCustomerData;

interface ReminderCustomerReader
{
    /** @return array<int, ReminderCustomerData> */
    public function candidates(): array;

    public function byId(int $customerId): ReminderCustomerData;
}
