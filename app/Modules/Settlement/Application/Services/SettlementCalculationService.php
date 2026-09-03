<?php

namespace App\Modules\Settlement\Application\Services;

use App\Modules\Agent\Application\Contracts\SettlementAgentGateway;
use App\Modules\Agent\Application\Data\SettlementAgentData;
use App\Modules\Order\Application\Contracts\SettlementOrderReader;
use App\Modules\Order\Application\Data\SettlementOrderData;
use App\Modules\Settlement\Application\Exceptions\StructuredSettlementFailure;
use App\Modules\Settlement\Infrastructure\Models\OrderCommission;
use Carbon\CarbonImmutable;

final readonly class SettlementCalculationService
{
    public function __construct(
        private SettlementOrderReader $orders,
        private SettlementAgentGateway $agents,
    ) {}

    /** @return array<int, int> */
    public function eligibleAgentIds(CarbonImmutable $periodStart, CarbonImmutable $periodEnd): array
    {
        return $this->agents->eligibleForPeriod($periodStart, $periodEnd);
    }

    /** @return array{agent: SettlementAgentData, orders: array<int, SettlementOrderData>, commissions: array<int, OrderCommission>, total_consumption_krw: int, total_commission_krw: int, item_count: int} */
    public function calculate(int $agentId, CarbonImmutable $periodStart, CarbonImmutable $periodEnd): array
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

        return [
            'agent' => $agent,
            'orders' => $orders,
            'commissions' => $commissions,
            'total_consumption_krw' => array_sum(array_map(static fn ($order): int => $order->amountKrw, $orders)),
            'total_commission_krw' => (int) array_sum(array_map(static fn (OrderCommission $commission): int => (int) $commission->amount_krw, $commissions)),
            'item_count' => count($orders),
        ];
    }
}
