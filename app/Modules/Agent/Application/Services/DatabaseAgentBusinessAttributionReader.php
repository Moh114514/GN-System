<?php

namespace App\Modules\Agent\Application\Services;

use App\Modules\Agent\Application\Contracts\AgentBusinessAttributionReader;
use App\Modules\Agent\Infrastructure\Models\AgentBusinessGroupAssignment;
use App\Modules\Auth\Application\Contracts\BusinessGroupReferenceReader;
use Carbon\CarbonImmutable;

final readonly class DatabaseAgentBusinessAttributionReader implements AgentBusinessAttributionReader
{
    public function __construct(private BusinessGroupReferenceReader $groups) {}

    public function forAgentOnDate(int $agentId, CarbonImmutable $date): ?array
    {
        $assignment = AgentBusinessGroupAssignment::query()
            ->where('agent_id', $agentId)
            ->whereDate('effective_from', '<=', $date->toDateString())
            ->where(function ($query) use ($date): void {
                $query->whereNull('effective_until')
                    ->orWhereDate('effective_until', '>=', $date->toDateString());
            })
            ->latest('effective_from')
            ->latest('id')
            ->first();
        if ($assignment === null) {
            return null;
        }

        $group = collect($this->groups->businessGroups())->firstWhere('id', (int) $assignment->business_group_id);

        return [
            'assignment_id' => (int) $assignment->id,
            'business_group_id' => (int) $assignment->business_group_id,
            'business_group_code' => (string) ($group['code'] ?? ''),
            'business_group_name' => (string) ($group['name'] ?? ''),
            'effective_from' => $assignment->effective_from->format('Y-m-d'),
            'effective_until' => $assignment->effective_until?->format('Y-m-d'),
            'agent_id' => $agentId,
            'occurred_on' => $date->toDateString(),
            'source' => 'agent_business_group_assignment',
        ];
    }
}
