<?php

namespace App\Modules\Customer\Application\Contracts;

use Carbon\CarbonImmutable;

interface ReportCustomerReader
{
    /**
     * @return array{
     *   total: int,
     *   items: array<int, array{id: int, code: string, name: string, status: string}>
     * }
     */
    public function globalSearch(string $query, int $limit): array;

    public function customerIdForPassport(string $passport): ?int;

    /**
     * @param  array<int, int>  $ids
     * @return array<int, string>
     */
    public function namesByIds(array $ids): array;

    /** @return array<int, int> */
    public function idsOrderedByName(): array;

    /** @return array<int, array{id: int, name: string}> */
    public function customerOptions(): array;

    /**
     * @return array{
     *   new_customers: int,
     *   active_customers: int,
     *   total_customers: int,
     *   arrived_customers: int,
     *   source_distribution: array<int, array{source_type: string, source_id: int, key: string, value: int}>,
     *   recent_customers: array<int, array{
     *     id: int,
     *     code: string,
     *     name: string,
     *     source_type: string,
     *     source_id: int,
     *     source_name: string,
     *     status_key: string,
     *     status_name: string,
     *     status_translation_key: string|null,
     *     owner_id: int,
     *     created_on: string
     *   }>
     * }
     */
    public function dashboard(CarbonImmutable $from, CarbonImmutable $to): array;

    /**
     * @param  list<int>  $ownerIds
     * @return array{
     *   total_customers: int,
     *   new_customers: int,
     *   unassigned_customers: int,
     *   pending_transfer_requests: int,
     *   owners: array<int, array{customers: int, new_customers: int, booked: int, arrived: int, treatment_completed: int}>
     * }
     */
    public function teamOverview(array $ownerIds, CarbonImmutable $from, CarbonImmutable $to): array;
}
