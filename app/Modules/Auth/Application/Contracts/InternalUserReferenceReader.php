<?php

namespace App\Modules\Auth\Application\Contracts;

interface InternalUserReferenceReader
{
    /** @return list<array{id: int, name: string}> */
    public function eligibleUsers(): array;

    /** @return list<array{id: int, name: string}> */
    public function eligibleDirectCustomerManagers(): array;

    public function isEligible(int $id): bool;
}
