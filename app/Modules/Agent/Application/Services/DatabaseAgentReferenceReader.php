<?php

namespace App\Modules\Agent\Application\Services;

use App\Modules\Agent\Application\Contracts\AgentReferenceReader;
use App\Modules\Agent\Infrastructure\Models\Agent;
use App\Modules\Auth\Application\Contracts\AccessContextResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final class DatabaseAgentReferenceReader implements AgentReferenceReader
{
    public function __construct(private readonly AccessContextResolver $access) {}

    public function activeAgents(): array
    {
        return $this->serialize(
            $this->scoped(Agent::query())->where('cooperation_status', 'active')->orderBy('name')->get(['id', 'code', 'name', 'cooperation_status']),
        );
    }

    public function agentsByIds(array $ids): array
    {
        return $this->serialize(
            $this->scoped(Agent::query())->whereKey(array_values(array_unique($ids)))->get(['id', 'code', 'name', 'cooperation_status']),
        );
    }

    public function matchingAgents(string $search): array
    {
        $search = trim($search);

        return $this->serialize(
            $this->scoped(Agent::query())
                ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                    $term = '%'.$search.'%';
                    $query->where('name', 'ilike', $term)
                        ->orWhere('code', 'ilike', $term);
                }))
                ->orderBy('name')
                ->get(['id', 'code', 'name', 'cooperation_status']),
        );
    }

    public function agentById(int $id): array
    {
        $agent = $this->scoped(Agent::query())->findOrFail($id, ['id', 'code', 'name', 'cooperation_status']);

        return [
            'id' => (int) $agent->id,
            'code' => (string) $agent->code,
            'name' => (string) $agent->name,
            'cooperation_status' => (string) $agent->cooperation_status,
        ];
    }

    /**
     * @param  Collection<int, Agent>  $agents
     * @return array<int, array{id: int, code: string, name: string, cooperation_status: string}>
     */
    private function serialize(Collection $agents): array
    {
        $result = [];
        foreach ($agents as $agent) {
            $result[(int) $agent->id] = [
                'id' => (int) $agent->id,
                'code' => (string) $agent->code,
                'name' => (string) $agent->name,
                'cooperation_status' => (string) $agent->cooperation_status,
            ];
        }

        return $result;
    }

    /**
     * @param  Builder<Agent>  $query
     * @return Builder<Agent>
     */
    private function scoped(Builder $query): Builder
    {
        $context = $this->access->current();
        if ($context->isSuperAdmin()) {
            return $query;
        }

        return ! $context->hasEffectiveBusinessScope()
            ? $query->whereRaw('1 = 0')
            : $query->whereKey($context->agentIds);
    }
}
