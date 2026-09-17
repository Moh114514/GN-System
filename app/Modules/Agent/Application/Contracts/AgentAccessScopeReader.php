<?php

namespace App\Modules\Agent\Application\Contracts;

interface AgentAccessScopeReader
{
    /**
     * @param  array<int, int>  $businessGroupIds
     * @return list<int>
     */
    public function agentIdsForBusinessGroups(array $businessGroupIds, ?string $onDate = null): array;
}
