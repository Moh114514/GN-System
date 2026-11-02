<?php

namespace App\Modules\Settlement\Application\Contracts;

use App\Modules\Settlement\Application\Data\CompletedOrderCommissionCorrectionData;
use App\Modules\Settlement\Application\Data\CompletedOrderCommissionData;
use Carbon\CarbonImmutable;

interface DailyCommissionGateway
{
    /** @return array{agent_name: string, grade_name: string, rate_bps: int, effective_month: string, rule_source: string, rule_id: int} */
    public function previewForOrder(int $agentId, int $institutionId, CarbonImmutable $occurredOn): array;

    public function recordForCompletedOrder(CompletedOrderCommissionData $data): int;

    /** @return array{rate_bps: int, commission_krw: int, rule_source: string, rule_id: int|null, effective_month: string|null} */
    public function correctForCompletedOrder(CompletedOrderCommissionCorrectionData $data): array;

    public function rollbackForOrder(int $orderId): void;
}
