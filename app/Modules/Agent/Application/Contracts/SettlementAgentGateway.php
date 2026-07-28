<?php

namespace App\Modules\Agent\Application\Contracts;

use App\Modules\Agent\Application\Data\SettlementAgentData;
use Carbon\CarbonImmutable;

interface SettlementAgentGateway
{
    /** @return array<int, SettlementAgentData> */
    public function activeForMonth(CarbonImmutable $month): array;

    public function forMonth(int $agentId, CarbonImmutable $month): SettlementAgentData;

    public function recommendation(int $agentId, CarbonImmutable $month, int $commissionKrw): SettlementAgentData;

    public function scheduleGrade(int $agentId, int $gradeId, CarbonImmutable $effectiveMonth, int $actorId, string $reason): void;
}
