<?php

namespace App\Modules\Agent\Application\Services;

use App\Modules\Agent\Application\Contracts\AgentAccessScopeReader;
use App\Modules\Agent\Infrastructure\Models\AgentBusinessGroupAssignment;

final class DatabaseAgentAccessScopeReader implements AgentAccessScopeReader
{
    public function agentIdsForBusinessGroups(array $businessGroupIds, ?string $onDate = null): array
    {
        $groupIds = array_values(array_unique(array_filter(array_map('intval', $businessGroupIds), fn (int $id): bool => $id > 0)));
        if ($groupIds === []) {
            return [];
        }

        $date = $onDate ?? now()->toDateString();

        return AgentBusinessGroupAssignment::query()
            ->whereIn('business_group_id', $groupIds)
            ->whereDate('effective_from', '<=', $date)
            ->where(function ($query) use ($date): void {
                $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', $date);
            })
            ->orderBy('agent_id')
            ->pluck('agent_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
