<?php

namespace App\Modules\Config\Application\Services;

use App\Modules\Config\Application\Contracts\InstitutionReferenceReader;
use App\Modules\Config\Infrastructure\Models\Institution;
use Illuminate\Database\Eloquent\Collection;

final class DatabaseInstitutionReferenceReader implements InstitutionReferenceReader
{
    public function activeInstitutions(): array
    {
        return $this->serialize(
            Institution::query()->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']),
        );
    }

    public function institutionsByIds(array $ids): array
    {
        return $this->serialize(
            Institution::query()->whereKey(array_values(array_unique($ids)))->get(['id', 'code', 'name']),
        );
    }

    /**
     * @param  Collection<int, Institution>  $institutions
     * @return array<int, array{id: int, code: string, name: string}>
     */
    private function serialize(Collection $institutions): array
    {
        $result = [];
        foreach ($institutions as $institution) {
            $result[(int) $institution->id] = [
                'id' => (int) $institution->id,
                'code' => (string) $institution->code,
                'name' => (string) $institution->name,
            ];
        }

        return $result;
    }
}
