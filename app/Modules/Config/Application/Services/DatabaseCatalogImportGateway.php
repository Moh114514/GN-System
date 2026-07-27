<?php

namespace App\Modules\Config\Application\Services;

use App\Modules\Config\Application\Contracts\CatalogImportGateway;
use App\Modules\Config\Application\Data\InstitutionImportData;
use App\Modules\Config\Infrastructure\Models\Institution;
use App\Modules\Config\Infrastructure\Models\InstitutionAlias;
use DateTimeInterface;
use Illuminate\Support\Str;

final class DatabaseCatalogImportGateway implements CatalogImportGateway
{
    public function resolveInstitutionId(string $nameOrAlias): ?int
    {
        $needle = $this->normalize($nameOrAlias);

        $institution = Institution::query()
            ->whereRaw('LOWER(name) = ?', [$needle])
            ->first();

        if ($institution !== null) {
            return $institution->id;
        }

        return InstitutionAlias::query()
            ->whereRaw('LOWER(alias) = ?', [$needle])
            ->value('institution_id');
    }

    public function upsertInstitution(InstitutionImportData $data): int
    {
        $code = $data->code ?? Str::upper(Str::slug($data->name, '_'));
        $institution = Institution::query()->updateOrCreate(
            ['code' => $code],
            [
                'name' => trim($data->name),
                'is_active' => true,
                'import_batch_id' => $data->importBatchId,
            ],
        );

        foreach (array_unique([$data->name, ...$data->aliases]) as $alias) {
            InstitutionAlias::query()->updateOrCreate(
                ['alias' => trim($alias)],
                ['institution_id' => $institution->id],
            );
        }

        return $institution->id;
    }

    public function deleteImportedByBatch(string $batchId): int
    {
        return Institution::query()->where('import_batch_id', $batchId)->delete();
    }

    public function rollbackBlockers(string $batchId, DateTimeInterface $completedAt): array
    {
        return Institution::query()
            ->where('import_batch_id', $batchId)
            ->where('updated_at', '>', $completedAt)
            ->pluck('id')
            ->map(fn (int $id): string => "institutions:{$id}")
            ->all();
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? $value));
    }
}
