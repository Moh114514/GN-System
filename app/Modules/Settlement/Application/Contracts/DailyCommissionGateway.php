<?php

namespace App\Modules\Settlement\Application\Contracts;

use App\Modules\Settlement\Application\Data\CompletedOrderCommissionData;
use Carbon\CarbonImmutable;

interface DailyCommissionGateway
{
    /** @return array{agent_name: string, grade_name: string, rate_bps: int, effective_month: string, rule_source: string} */
    public function previewForOrder(int $agentId, int $institutionId, CarbonImmutable $occurredOn): array;

    public function recordForCompletedOrder(CompletedOrderCommissionData $data): int;

    public function rollbackForOrder(int $orderId): void;
}
