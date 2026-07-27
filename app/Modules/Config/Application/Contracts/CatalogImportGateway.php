<?php

namespace App\Modules\Config\Application\Contracts;

use App\Modules\Config\Application\Data\InstitutionImportData;
use DateTimeInterface;

interface CatalogImportGateway
{
    public function resolveInstitutionId(string $nameOrAlias): ?int;

    public function upsertInstitution(InstitutionImportData $data): int;

    /** @return array<int, string> */
    public function rollbackBlockers(string $batchId, DateTimeInterface $completedAt): array;

    public function deleteImportedByBatch(string $batchId): int;
}
