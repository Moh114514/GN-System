<?php

namespace App\Modules\Agent\Application\Contracts;

use App\Modules\Agent\Application\Data\SettlementAgentData;
use Carbon\CarbonImmutable;

interface SettlementAgentGateway
{
    /** @return array<int, int> */
    public function eligibleForPeriod(CarbonImmutable $periodStart, CarbonImmutable $periodEnd): array;

    public function forMonth(int $agentId, CarbonImmutable $month): SettlementAgentData;

    public function scheduleGrade(int $agentId, int $gradeId, CarbonImmutable $effectiveMonth, int $actorId, string $reason): void;
}
