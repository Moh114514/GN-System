<?php

namespace App\Modules\Customer\Application\Contracts;

interface ReferenceConfigurationImportGateway
{
    /** @return array<int, string> */
    public function directSalesSourceCodes(): array;

    /** @param array<int, array<string, mixed>> $rows */
    public function upsertDirectSalesSources(array $rows, string $batchId): void;
}
