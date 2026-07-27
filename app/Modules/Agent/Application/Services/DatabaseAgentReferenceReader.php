<?php

namespace App\Modules\Agent\Application\Services;

use App\Modules\Agent\Application\Contracts\AgentReferenceReader;
use App\Modules\Agent\Infrastructure\Models\Agent;
use Illuminate\Database\Eloquent\Collection;

final class DatabaseAgentReferenceReader implements AgentReferenceReader
{
    public function activeAgents(): array
    {
        return $this->serialize(
            Agent::query()->where('cooperation_status', 'active')->orderBy('name')->get(['id', 'code', 'name']),
        );
    }

    public function agentsByIds(array $ids): array
    {
        return $this->serialize(
            Agent::query()->whereKey(array_values(array_unique($ids)))->get(['id', 'code', 'name']),
        );
    }

    /**
     * @param  Collection<int, Agent>  $agents
     * @return array<int, array{id: int, code: string, name: string}>
     */
    private function serialize(Collection $agents): array
    {
        $result = [];
        foreach ($agents as $agent) {
            $result[(int) $agent->id] = [
                'id' => (int) $agent->id,
                'code' => (string) $agent->code,
                'name' => (string) $agent->name,
            ];
        }

        return $result;
    }
}
