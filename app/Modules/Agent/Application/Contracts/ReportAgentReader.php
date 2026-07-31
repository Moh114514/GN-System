<?php

namespace App\Modules\Agent\Application\Contracts;

interface ReportAgentReader
{
    /**
     * @return array{
     *   total: int,
     *   items: array<int, array{id: int, code: string, name: string, status: string}>
     * }
     */
    public function globalSearch(string $query, int $limit): array;

    /**
     * @param  array<int, int>  $ids
     * @return array<int, string>
     */
    public function namesByIds(array $ids): array;

    /** @return array<int, int> */
    public function idsOrderedByName(): array;

    /** @return array<int, array{id: int, name: string}> */
    public function activeAgents(): array;

    /** @return array<int, array{key: string, value: int}> */
    public function currentGradeDistribution(): array;
}
