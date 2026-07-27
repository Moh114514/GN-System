<?php

namespace App\Modules\Customer\Application\Contracts;

use App\Modules\Customer\Application\Data\CustomerImportData;
use DateTimeInterface;

interface CustomerImportGateway
{
    /** @return array<int, int> */
    public function duplicateCandidateIds(?string $contact, ?string $identityDocument): array;

    public function resolveCustomerId(string $code): ?int;

    public function resolveDirectSalesSourceId(string $code): ?int;

    public function upsertDirectSalesSource(string $code, string $name): int;

    public function upsertCustomer(CustomerImportData $data): int;

    /** @return array<int, string> */
    public function rollbackBlockers(string $batchId, DateTimeInterface $completedAt): array;

    public function deleteImportedByBatch(string $batchId): int;
}
