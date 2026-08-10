<?php

namespace App\Modules\Settlement\Application\Services;

use App\Modules\Agent\Application\Contracts\AgentReferenceReader;
use App\Modules\Settlement\Infrastructure\Models\Settlement;
use App\Modules\Settlement\Infrastructure\Models\SettlementRunMember;
use Illuminate\Database\Eloquent\Collection;

final readonly class SettlementDisplayReader
{
    public function __construct(private AgentReferenceReader $agents) {}

    /** @return array{id: int|null, code: string, name: string} */
    public function agent(Settlement $settlement): array
    {
        return $this->forSettlements(new Collection([$settlement]))[(int) $settlement->id];
    }

    /**
     * @param  Collection<int, Settlement>  $settlements
     * @return array<int, array{id: int|null, code: string, name: string}>
     */
    public function forSettlements(Collection $settlements): array
    {
        $agentIds = $settlements->map(static fn (Settlement $settlement): int => (int) $settlement->agent_id)->unique()->values()->all();
        $references = $this->agents->agentsByIds($agentIds);
        $result = [];
        foreach ($settlements as $settlement) {
            $snapshotAgent = is_array(data_get($settlement->snapshot, 'agent')) ? data_get($settlement->snapshot, 'agent') : [];
            $agentId = (int) $settlement->agent_id;
            $reference = $references[$agentId] ?? null;
            $result[(int) $settlement->id] = [
                'id' => $agentId > 0 ? $agentId : null,
                'code' => (string) ($snapshotAgent['code'] ?? $reference['code'] ?? ''),
                'name' => (string) ($snapshotAgent['name'] ?? $reference['name'] ?? __('settlements.labels.unknown_agent').' #'.$agentId),
            ];
        }

        return $result;
    }

    /**
     * @param  Collection<int, SettlementRunMember>  $members
     * @return array<int, array{id: int|null, code: string, name: string}>
     */
    public function forMembers(Collection $members): array
    {
        $settlements = $members->pluck('settlement')->filter();
        $agentIds = $settlements->map(static fn (Settlement $settlement): int => (int) $settlement->agent_id)->unique()->values()->all();
        $references = $this->agents->agentsByIds($agentIds);
        $result = [];
        foreach ($members as $member) {
            $settlement = $member->settlement;
            if (! $settlement instanceof Settlement) {
                $result[(int) $member->id] = [
                    'id' => (int) $member->agent_id,
                    'code' => '',
                    'name' => __('settlements.labels.unknown_agent').' #'.$member->agent_id,
                ];

                continue;
            }
            $snapshotAgent = is_array(data_get($settlement->snapshot, 'agent')) ? data_get($settlement->snapshot, 'agent') : [];
            $agentId = (int) $settlement->agent_id;
            $reference = $references[$agentId] ?? null;
            $result[(int) $member->id] = [
                'id' => $agentId,
                'code' => (string) ($snapshotAgent['code'] ?? $reference['code'] ?? ''),
                'name' => (string) ($snapshotAgent['name'] ?? $reference['name'] ?? __('settlements.labels.unknown_agent').' #'.$agentId),
            ];
        }

        return $result;
    }
}
