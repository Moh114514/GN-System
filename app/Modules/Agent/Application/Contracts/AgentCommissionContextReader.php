<?php

namespace App\Modules\Agent\Application\Contracts;

use App\Modules\Agent\Application\Data\AgentCommissionContextData;
use Carbon\CarbonImmutable;

interface AgentCommissionContextReader
{
    public function forMonth(int $agentId, CarbonImmutable $month): AgentCommissionContextData;
}
