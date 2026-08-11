<?php

namespace App\Modules\Settlement\Application\Services;

use App\Modules\Order\Application\Contracts\SettlementOrderReader;
use App\Modules\Settlement\Application\Data\SettlementFreshnessResult;
use App\Modules\Settlement\Infrastructure\Models\OrderCommission;
use App\Modules\Settlement\Infrastructure\Models\Settlement;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class SettlementFreshnessChecker
{
    public function __construct(private SettlementOrderReader $orders) {}

    public function check(Settlement $settlement): SettlementFreshnessResult
    {
        $orders = $this->orders->completedForAgent(
            (int) $settlement->agent_id,
            CarbonImmutable::parse($settlement->period_start),
            CarbonImmutable::parse($settlement->period_end),
        );
        $orderIds = array_values(array_map(static fn ($order): int => $order->orderId, $orders));
        sort($orderIds);
        $commissions = $orderIds === [] ? collect() : OrderCommission::query()->whereIn('order_id', $orderIds)->get()->keyBy('order_id');
        $missing = array_values(array_diff($orderIds, $commissions->keys()->map(static fn ($id): int => (int) $id)->all()));
        sort($missing);
        $items = DB::table('settlement_items')->where('settlement_id', $settlement->id)->get();
        $settlementIds = [];
        $settlementSnapshots = [];
        $settlementConsumption = 0;
        $settlementCommission = 0;
        foreach ($items as $item) {
            $snapshot = is_string($item->rule_snapshot) ? json_decode($item->rule_snapshot, true) : $item->rule_snapshot;
            $orderId = (int) data_get($snapshot, 'order.id');
            if ($orderId > 0) {
                $settlementIds[] = $orderId;
                $settlementSnapshots[$orderId] = is_array($snapshot) ? $snapshot : [];
            }
            $settlementConsumption += (int) $item->consumption_krw;
            $settlementCommission += (int) $item->commission_krw;
        }
        $settlementIds = array_values(array_unique($settlementIds));
        sort($settlementIds);
        $added = array_values(array_diff($orderIds, $settlementIds));
        $removed = array_values(array_diff($settlementIds, $orderIds));
        sort($added);
        sort($removed);
        $currentConsumption = (int) array_sum(array_map(static fn ($order): int => $order->amountKrw, $orders));
        $currentCommission = (int) $commissions->sum('amount_krw');
        $snapshotChanged = false;
        foreach ($orderIds as $orderId) {
            $storedSnapshot = $settlementSnapshots[$orderId] ?? null;
            if (! is_array($storedSnapshot) || ! $commissions->has($orderId)) {
                $snapshotChanged = true;
                break;
            }
            unset($storedSnapshot['order']);
            if ($this->normaliseSnapshot($storedSnapshot) !== $this->normaliseSnapshot($commissions->get($orderId)->rule_snapshot)) {
                $snapshotChanged = true;
                break;
            }
        }
        $stale = $missing !== []
            || $orderIds !== $settlementIds
            || count($orders) !== $items->count()
            || $currentConsumption !== $settlementConsumption
            || $currentCommission !== $settlementCommission
            || $snapshotChanged;

        return new SettlementFreshnessResult(
            status: $stale ? 'stale' : 'fresh',
            currentItemCount: count($orders),
            currentConsumptionKrw: $currentConsumption,
            currentCommissionKrw: $currentCommission,
            settlementItemCount: $items->count(),
            settlementConsumptionKrw: $settlementConsumption,
            settlementCommissionKrw: $settlementCommission,
            currentOrderIds: $orderIds,
            settlementOrderIds: $settlementIds,
            addedOrderIds: $added,
            removedOrderIds: $removed,
            missingCommissionOrderIds: $missing,
        );
    }

    private function normaliseSnapshot(mixed $snapshot): mixed
    {
        if (! is_array($snapshot)) {
            return $snapshot;
        }
        if (array_is_list($snapshot)) {
            return array_map(fn (mixed $value): mixed => $this->normaliseSnapshot($value), $snapshot);
        }

        $normalised = [];
        foreach ($snapshot as $key => $value) {
            $normalised[(string) $key] = $this->normaliseSnapshot($value);
        }
        ksort($normalised);

        return $normalised;
    }

    public function isStale(Settlement $settlement): bool
    {
        return $this->check($settlement)->isStale();
    }
}
