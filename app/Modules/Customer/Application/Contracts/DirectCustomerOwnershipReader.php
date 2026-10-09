<?php

namespace App\Modules\Customer\Application\Contracts;

interface DirectCustomerOwnershipReader
{
    public function countOwnedByUser(int $userId): int;
}
