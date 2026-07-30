<?php

namespace App\Modules\Config\Application\Contracts;

interface ReportConfigReader
{
    /**
     * @param  array<int, int>  $ids
     * @return array<int, string>
     */
    public function institutionNamesByIds(array $ids): array;

    /** @return array<int, int> */
    public function institutionIdsOrderedByName(): array;

    /** @return array<int, array{id: int, name: string}> */
    public function activeInstitutions(): array;

    public function integerParameter(string $key, int $default): int;
}
