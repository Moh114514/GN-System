<?php

namespace App\Modules\Config\Application\Data;

final readonly class InstitutionImportData
{
    /**
     * @param  array<int, string>  $aliases
     */
    public function __construct(
        public string $name,
        public array $aliases = [],
        public ?string $code = null,
        public ?string $importBatchId = null,
    ) {}
}
