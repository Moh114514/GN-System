<?php

namespace App\Modules\Agent\Application\Contracts;

use Carbon\CarbonImmutable;

interface AgentBusinessAttributionReader
{
    /** @return array<string, mixed>|null */
    public function forAgentOnDate(int $agentId, CarbonImmutable $date): ?array;
}
