<?php

namespace App\Modules\Order\Application\Services;

use App\Modules\Order\Application\Contracts\SettlementOrderReader;
use App\Modules\Order\Application\Data\SettlementOrderData;
use App\Modules\Order\Infrastructure\Models\Order;
use Carbon\CarbonImmutable;

final class DatabaseSettlementOrderReader implements SettlementOrderReader
{
    public function completedForAgent(int $agentId, CarbonImmutable $periodStart, CarbonImmutable $periodEnd): array
    {
        return Order::query()
            ->where('channel', 'agent')
            ->where('agent_id', $agentId)
            ->where('status', 'completed')
            ->whereBetween('completed_on', [$periodStart, $periodEnd])
            ->orderBy('completed_on')
            ->orderBy('id')
            ->get()
            ->map(fn (Order $order): SettlementOrderData => new SettlementOrderData(
                orderId: (int) $order->id,
                customerId: (int) $order->customer_id,
                institutionId: (int) $order->institution_id,
                agentId: (int) $order->agent_id,
                projectName: (string) $order->project_name,
                amountKrw: (int) $order->amount_krw,
                completedOn: CarbonImmutable::parse($order->completed_on),
            ))
            ->all();
    }
}
