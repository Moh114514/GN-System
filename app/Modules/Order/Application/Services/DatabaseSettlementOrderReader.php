<?php

namespace App\Modules\Order\Application\Services;

use App\Modules\Auth\Application\Contracts\AccessContextResolver;
use App\Modules\Order\Application\Contracts\SettlementOrderReader;
use App\Modules\Order\Application\Data\SettlementOrderData;
use App\Modules\Order\Infrastructure\Models\Order;
use Carbon\CarbonImmutable;

final class DatabaseSettlementOrderReader implements SettlementOrderReader
{
    public function __construct(private readonly AccessContextResolver $access) {}

    public function completedForAgent(int $agentId, CarbonImmutable $periodStart, CarbonImmutable $periodEnd): array
    {
        abort_unless($this->access->current()->canViewAgent($agentId), 404);

        return Order::query()
            ->where('agent_id', $agentId)
            ->where('status', 'completed')
            ->where('record_status', 'active')
            ->whereNotNull('occurred_on')
            ->whereBetween('occurred_on', [$periodStart, $periodEnd])
            ->orderBy('occurred_on')
            ->orderBy('id')
            ->get()
            ->map(fn (Order $order): SettlementOrderData => new SettlementOrderData(
                orderId: (int) $order->id,
                customerId: (int) $order->customer_id,
                institutionId: (int) $order->institution_id,
                agentId: (int) $order->agent_id,
                projectName: (string) $order->project_name,
                amountKrw: (int) $order->amount_krw,
                completedOn: CarbonImmutable::parse($order->occurred_on),
            ))
            ->all();
    }

    /** @param array<int, int> $orderIds @return array<int, int> */
    public function existingOrderIds(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        $query = Order::query()
            ->whereIn('id', $orderIds)
            ->when(! $this->access->current()->isSuperAdmin(), fn ($query) => $query->whereIn('agent_id', $this->access->current()->agentIds));

        return $query->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }
}
