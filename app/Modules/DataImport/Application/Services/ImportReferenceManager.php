<?php

namespace App\Modules\DataImport\Application\Services;

use App\Modules\Config\Application\Contracts\CatalogImportGateway;
use App\Modules\Config\Application\Data\InstitutionImportData;

final readonly class ImportReferenceManager
{
    public function __construct(private CatalogImportGateway $catalog) {}

    /** @param array<int, string> $aliases */
    public function upsertInstitution(string $code, string $name, array $aliases): int
    {
        return $this->catalog->upsertInstitution(new InstitutionImportData(
            code: strtoupper(trim($code)),
            name: trim($name),
            aliases: array_values(array_filter(array_map('trim', $aliases))),
            importBatchId: null,
        ));
    }
}
