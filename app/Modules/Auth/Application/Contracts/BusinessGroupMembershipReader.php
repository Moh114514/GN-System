<?php

namespace App\Modules\Auth\Application\Contracts;

interface BusinessGroupMembershipReader
{
    /**
     * @param  list<int>|null  $businessGroupIds
     * @return list<int>
     */
    public function activeCustomerServiceUserIds(?array $businessGroupIds = null, ?string $onDate = null): array;

    /** @param list<int>|null $businessGroupIds */
    public function isActiveCustomerServiceInGroups(int $userId, ?array $businessGroupIds = null, ?string $onDate = null): bool;
}
