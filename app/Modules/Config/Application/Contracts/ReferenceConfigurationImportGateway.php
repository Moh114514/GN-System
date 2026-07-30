<?php

namespace App\Modules\Config\Application\Contracts;

interface ReferenceConfigurationImportGateway
{
    /** @return array<int, string> */
    public function institutionCodes(): array;

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, int>
     */
    public function upsertInstitutions(array $rows, string $batchId): array;
}
