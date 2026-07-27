<?php

namespace App\Modules\Reminder\Application\Contracts;

use App\Modules\Reminder\Application\Data\CustomerFollowupData;

interface CustomerFollowupGateway
{
    public function record(CustomerFollowupData $data): int;

    /** @return array<int, array<string, mixed>> */
    public function timelineForCustomer(int $customerId): array;
}
