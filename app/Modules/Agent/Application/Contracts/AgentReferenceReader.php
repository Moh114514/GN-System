<?php

namespace App\Modules\Agent\Application\Contracts;

interface AgentReferenceReader
{
    /** @return array<int, array{id: int, code: string, name: string}> */
    public function activeAgents(): array;

    /** @param array<int, int> $ids
     * @return array<int, array{id: int, code: string, name: string}>
     */
    public function agentsByIds(array $ids): array;
}
