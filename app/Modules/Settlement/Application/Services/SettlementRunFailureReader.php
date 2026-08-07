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
                agentCode: $agent['code'] ?? __('settlements.failure_fallbacks.agent_code'),
                agentName: $agent['name'] ?? __('settlements.failure_fallbacks.agent_name'),
                reason: $this->reason($reason),
            );
        }

        return $failures;
    }

    private function reason(mixed $reason): string
    {
        if (is_array($reason) && in_array($reason['message_key'] ?? null, [
            'settlements.failure_reasons.missing_commission_snapshot',
            'settlements.failure_reasons.agent_policy_missing',
            'settlements.failure_reasons.existing_settlement',
            'settlements.failure_reasons.business_rule',
            'settlements.failure_reasons.unexpected',
        ], true)) {
            return __($reason['message_key'], is_array($reason['parameters'] ?? null) ? $reason['parameters'] : []);
        }
        $legacy = (string) $reason;
        if (preg_match('/^已完成订单\s*(\d+)\s*缺少推广费快照。?$/u', $legacy, $matches) === 1) {
            return __('settlements.failure_reasons.missing_commission_snapshot', ['order_id' => $matches[1]]);
        }
        if ($legacy === '代理商在当月没有生效政策等级。') {
            return __('settlements.failure_reasons.agent_policy_missing');
        }
        if (preg_match('/^系统处理失败，请联系管理员并提供参考编号：(.+?)。?$/u', $legacy, $matches) === 1) {
            return __('settlements.failure_reasons.unexpected', ['reference' => $matches[1]]);
        }

        return __('settlements.failure_reasons.legacy_unknown');
    }
}
