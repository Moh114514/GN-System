<?php

namespace App\Modules\Settlement\Application\Services;

use App\Modules\Agent\Application\Contracts\SettlementAgentGateway;
use App\Modules\Settlement\Application\Data\SettlementRunFailureData;
use App\Modules\Settlement\Infrastructure\Models\SettlementRun;
use Carbon\CarbonImmutable;

final readonly class SettlementRunFailureReader
{
    public function __construct(private SettlementAgentGateway $agents) {}

    /** @return array<int, SettlementRunFailureData> */
    public function read(SettlementRun $run): array
    {
        $month = CarbonImmutable::parse($run->period_end);
        $failures = [];
        foreach ($run->errors ?? [] as $agentId => $reason) {
            $agentId = (int) $agentId;
            if ($agentId <= 0) {
                continue;
            }
            $agent = $this->agents->forMonth($agentId, $month);
            $failures[] = new SettlementRunFailureData(
                agentId: $agentId,
                agentCode: $agent->code,
                agentName: $agent->name,
                reason: (string) $reason,
            );
        }

        return $failures;
    }
}
