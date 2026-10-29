<?php

namespace App\Modules\Reminder\Application\Contracts;

use App\Modules\Reminder\Application\Data\CustomerFollowupData;
use Carbon\CarbonImmutable;

interface CustomerFollowupGateway
{
    public function record(CustomerFollowupData $data): int;

    /**
     * @param  list<int>  $customerIds
     * @return array<int, array{last_followup_on: string|null, last_followup_content: string|null, next_followup_at: string|null, followup_status: string}>
     */
    public function summariesForCustomers(array $customerIds, CarbonImmutable $asOf): array;

    /** @return list<int> */
    public function customerIdsWithFollowupStatus(string $status, CarbonImmutable $asOf): array;

    /**
     * @param  list<int>  $customerIds
     * @return array{today: int, overdue: int, upcoming: int}
     */
    public function statusCountsForCustomers(array $customerIds, CarbonImmutable $asOf): array;

    /** @return array<int, array<string, mixed>> */
    public function timelineForCustomer(int $customerId): array;
}
