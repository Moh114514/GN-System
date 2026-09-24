<?php

namespace App\Modules\Customer\Application\Services;

use App\Modules\Customer\Application\Contracts\DirectCustomerOwnershipReader;
use App\Modules\Customer\Infrastructure\Models\Customer;

final class DatabaseDirectCustomerOwnershipReader implements DirectCustomerOwnershipReader
{
    public function countOwnedByUser(int $userId): int
    {
        return Customer::query()
            ->where('source_type', 'direct')
            ->where('owner_id', $userId)
            ->count();
    }
}
