<?php

namespace App\Modules\Settlement\Application\Services;

use App\Modules\Agent\Application\Contracts\AgentReferenceReader;
use App\Modules\Settlement\Application\Data\SettlementRunFailureData;
use App\Modules\Settlement\Infrastructure\Models\SettlementRun;

final readonly class SettlementRunFailureReader
{
    public function __construct(private AgentReferenceReader $agents) {}

    /** @return array<int, SettlementRunFailureData> */
    public function read(SettlementRun $run): array
    {
        $agentIds = [];
        foreach (array_keys($run->errors ?? []) as $rawAgentId) {
            $agentId = (int) $rawAgentId;
            if ($agentId > 0) {
                $agentIds[] = $agentId;
            }
        }
        $agents = $this->agents->agentsByIds(array_values(array_unique($agentIds)));
        $failures = [];
        foreach ($run->errors ?? [] as $agentId => $reason) {
            $agentId = (int) $agentId;
            if ($agentId <= 0) {
                continue;
            }
            $agent = $agents[$agentId] ?? null;
            $failures[] = new SettlementRunFailureData(
                agentId: $agentId,
                agentCode: $agent['code'] ?? '未知',
                agentName: $agent['name'] ?? '代理商不存在或已删除',
                reason: (string) $reason,
            );
        }

        return $failures;
    }
}
