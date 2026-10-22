<?php

namespace App\Modules\Settlement\Application\Services;

use App\Modules\Agent\Application\Contracts\SettlementAgentGateway;
use App\Modules\Agent\Application\Data\SettlementAgentData;
use App\Modules\Order\Application\Contracts\SettlementOrderReader;
use App\Modules\Order\Application\Data\SettlementOrderData;
use App\Modules\Settlement\Application\Exceptions\StructuredSettlementFailure;
use App\Modules\Settlement\Infrastructure\Models\OrderCommission;
use App\Modules\Settlement\Infrastructure\Models\Settlement;
use App\Modules\Settlement\Infrastructure\Models\SettlementAdjustment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

final readonly class SettlementCalculationService
{
    public function __construct(
        private SettlementOrderReader $orders,
        private SettlementAgentGateway $agents,
    ) {}

    /** @return array<int, int> */
    public function eligibleAgentIds(CarbonImmutable $periodStart, CarbonImmutable $periodEnd): array
    {
        $ids = array_values(array_unique([
            ...$this->agents->eligibleForPeriod($periodStart, $periodEnd),
            ...SettlementAdjustment::query()
                ->whereDate('eligible_period_start', '<=', $periodStart)
                ->where(function ($query) use ($periodStart, $periodEnd): void {
                    $query->whereNull('target_settlement_id')->orWhereIn('target_settlement_id',
                        Settlement::query()->select('id')
                            ->whereDate('period_start', $periodStart)->whereDate('period_end', $periodEnd));
                })
                ->pluck('agent_id')->map(static fn ($id): int => (int) $id)->all(),
        ]));
        sort($ids);

        return $ids;
    }

    /** @return array{agent: SettlementAgentData, orders: array<int, SettlementOrderData>, commissions: array<int, OrderCommission>, adjustments: Collection<int, SettlementAdjustment>, total_consumption_krw: int, total_commission_krw: int, item_count: int} */
    public function calculate(int $agentId, CarbonImmutable $periodStart, CarbonImmutable $periodEnd, bool $lockAdjustments = false): array
    {
        $agent = $this->agents->forMonth($agentId, $periodEnd);
        $orders = $this->orders->completedForAgent($agentId, $periodStart, $periodEnd);
        $orderIds = array_map(static fn ($order): int => $order->orderId, $orders);
        $commissions = OrderCommission::query()
            ->whereIn('order_id', $orderIds)
            ->get()
            ->keyBy('order_id')
            ->all();
        foreach ($orders as $order) {
            if (! array_key_exists($order->orderId, $commissions)) {
                throw new StructuredSettlementFailure(
                    'settlements.failure_reasons.missing_commission_snapshot',
                    ['order_id' => $order->orderId],
                );
            }
        }

        $settlementId = Settlement::query()->where('agent_id', $agentId)
            ->whereDate('period_start', $periodStart)->whereDate('period_end', $periodEnd)->value('id');
        $adjustments = SettlementAdjustment::query()
            ->where('agent_id', $agentId)
            ->whereDate('eligible_period_start', '<=', $periodStart)
            ->where(function ($query) use ($settlementId): void {
                $query->whereNull('target_settlement_id');
                if ($settlementId !== null) {
                    $query->orWhere('target_settlement_id', $settlementId);
                }
            })
            ->orderBy('id')
            ->when($lockAdjustments, fn ($query) => $query->lockForUpdate())
            ->get();

        return [
            'agent' => $agent,
            'orders' => $orders,
            'commissions' => $commissions,
            'adjustments' => $adjustments,
            'total_consumption_krw' => array_sum(array_map(static fn ($order): int => $order->amountKrw, $orders)) + (int) $adjustments->sum('consumption_delta_krw'),
            'total_commission_krw' => (int) array_sum(array_map(static fn (OrderCommission $commission): int => (int) $commission->amount_krw, $commissions)) + (int) $adjustments->sum('commission_delta_krw'),
            'item_count' => count($orders) + $adjustments->count(),
        ];
    }
}
