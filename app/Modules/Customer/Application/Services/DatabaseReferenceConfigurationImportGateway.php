<?php

namespace App\Modules\Customer\Application\Services;

use App\Modules\Customer\Application\Contracts\ReferenceConfigurationImportGateway;
use App\Modules\Customer\Infrastructure\Models\DirectSalesSource;

final readonly class DatabaseReferenceConfigurationImportGateway implements ReferenceConfigurationImportGateway
{
    public function directSalesSourceCodes(): array
    {
        return DirectSalesSource::query()->pluck('code')->all();
    }

    public function upsertDirectSalesSources(array $rows, string $batchId): void
    {
        foreach ($rows as $row) {
            $source = DirectSalesSource::query()->firstOrNew(['code' => $row['code']]);
            $source->fill([
                'name' => $row['name'],
                'is_active' => $row['is_active'],
            ]);
            if (! $source->exists) {
                $source->import_batch_id = $batchId;
            }
            $source->save();
        }
    }
}
