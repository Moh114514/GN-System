<?php

namespace App\Modules\Customer\Application\Contracts;

use Carbon\CarbonImmutable;

interface ReportCustomerReader
{
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
     *   source_distribution: array<int, array{source_type: string, source_id: int, key: string, value: int}>
     * }
     */
    public function dashboard(CarbonImmutable $from, CarbonImmutable $to): array;
}
