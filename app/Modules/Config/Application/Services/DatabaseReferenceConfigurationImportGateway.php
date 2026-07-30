<?php

namespace App\Modules\Config\Application\Services;

use App\Modules\Config\Application\Contracts\ReferenceConfigurationImportGateway;
use App\Modules\Config\Infrastructure\Models\Institution;
use App\Modules\Config\Infrastructure\Models\InstitutionAlias;

final readonly class DatabaseReferenceConfigurationImportGateway implements ReferenceConfigurationImportGateway
{
    public function institutionCodes(): array
    {
        return Institution::query()->pluck('code')->all();
    }

    public function upsertInstitutions(array $rows, string $batchId): array
    {
        foreach ($rows as $row) {
            $institution = Institution::query()->firstOrNew(['code' => $row['code']]);
            $institution->fill([
                'name' => $row['name'],
                'is_active' => $row['is_active'],
            ]);
            if (! $institution->exists) {
                $institution->import_batch_id = $batchId;
            }
            $institution->save();

            foreach ($row['aliases'] as $alias) {
                InstitutionAlias::query()->updateOrCreate(
                    ['alias' => $alias],
                    ['institution_id' => $institution->id],
                );
            }
        }

        return Institution::query()->pluck('id', 'code')->all();
    }
}
