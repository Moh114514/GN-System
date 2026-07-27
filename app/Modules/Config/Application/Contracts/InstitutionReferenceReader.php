<?php

namespace App\Modules\Config\Application\Contracts;

interface InstitutionReferenceReader
{
    /** @return array<int, array{id: int, code: string, name: string}> */
    public function activeInstitutions(): array;

    /** @param array<int, int> $ids
     * @return array<int, array{id: int, code: string, name: string}>
     */
    public function institutionsByIds(array $ids): array;
}
