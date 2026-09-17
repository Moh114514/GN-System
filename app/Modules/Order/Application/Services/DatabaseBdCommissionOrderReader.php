<?php

namespace App\Modules\Order\Application\Services;

use App\Modules\Auth\Application\Contracts\AccessContextResolver;
use App\Modules\Order\Application\Contracts\BdCommissionOrderReader;
use App\Modules\Order\Infrastructure\Models\Order;
use App\Modules\Settlement\Application\Data\BdCommissionOrderData;
use Carbon\CarbonImmutable;

final readonly class DatabaseBdCommissionOrderReader implements BdCommissionOrderReader
{
    public function __construct(private AccessContextResolver $access) {}

    public function completedBetween(CarbonImmutable $periodStart, CarbonImmutable $periodEnd): array
    {
        $context = $this->access->current();

        return Order::query()
            ->where('status', 'completed')
            ->where('record_status', 'active')
            ->whereNotNull('occurred_on')
            ->whereBetween('occurred_on', [$periodStart->toDateString(), $periodEnd->toDateString()])
            ->when(! $context->isSuperAdmin(), function ($query) use ($context): void {
                if (! $context->isBdManager() || $context->userId === null) {
                    $query->whereRaw('1 = 0');

                    return;
                }
                $query->whereJsonContains('business_attribution_snapshot->business_group->bd_manager->user_id', $context->userId);
            })
            ->orderBy('occurred_on')
            ->orderBy('id')
            ->get()
            ->map(fn (Order $order): BdCommissionOrderData => new BdCommissionOrderData(
                orderId: (int) $order->id,
                amountKrw: (int) $order->amount_krw,
                occurredOn: CarbonImmutable::parse($order->occurred_on),
                attributionSnapshot: is_array($order->business_attribution_snapshot)
                    ? $order->business_attribution_snapshot
                    : null,
            ))
            ->all();
    }
}
