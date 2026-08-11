<?php

namespace App\Modules\Agent\Application\Contracts;

interface AgentReferenceReader
{
    /** @return array<int, array{id: int, code: string, name: string, cooperation_status: string}> */
    public function activeAgents(): array;

    /** @param array<int, int> $ids
     * @return array<int, array{id: int, code: string, name: string, cooperation_status: string}>
     */
    public function agentsByIds(array $ids): array;

    /** @return array<int, array{id: int, code: string, name: string, cooperation_status: string}> */
    public function matchingAgents(string $search): array;

    /** @return array{id: int, code: string, name: string, cooperation_status: string} */
    public function agentById(int $id): array;
}
