<?php

namespace App\Modules\Settlement\Application\Services;

use App\Modules\Auth\Application\Contracts\AccessContextResolver;
use App\Modules\Customer\Application\Contracts\CustomerOrderReferenceReader;
use App\Modules\Settlement\Application\Contracts\OrderFinancialReader;
use App\Modules\Settlement\Infrastructure\Models\DirectOrderCommission;
use App\Modules\Settlement\Infrastructure\Models\OrderCommission;
use App\Modules\Settlement\Infrastructure\Models\SettlementAdjustment;
use Illuminate\Support\Facades\DB;

final class DatabaseOrderFinancialReader implements OrderFinancialReader
{
    public function __construct(
        private readonly AccessContextResolver $access,
        private readonly CustomerOrderReferenceReader $customers,
    ) {}

    public function forOrder(int $orderId, int $customerId): array
    {
        $commission = OrderCommission::query()->where('order_id', $orderId)->first();
        $directCommission = DirectOrderCommission::query()->where('order_id', $orderId)->where('status', 'active')->first();
        $corrections = SettlementAdjustment::query()
            ->where('source_order_id', $orderId)
            ->whereNotNull('original_settlement_id')
            ->orderBy('id')
            ->get();
        $correction = $corrections->last();
        $context = $this->access->current();
        if ($commission !== null) {
            abort_unless($context->canViewAgent((int) $commission->agent_id), 404);
        }
        if ($directCommission !== null) {
            $this->customers->customerForOrder($customerId);
        }
        $directCommissionVisible = $directCommission === null
            || $context->isSuperAdmin()
            || (int) $directCommission->owner_id === $context->userId;
        $item = DB::table('settlement_items')
            ->join('settlements', 'settlements.id', '=', 'settlement_items.settlement_id')
            ->where('settlement_items.order_commission_id', $commission === null ? 0 : $commission->id)
            ->select([
                'settlements.id as settlement_id',
                'settlement_items.id as item_id',
                'settlements.period_start',
                'settlements.period_end',
                'settlements.status',
            ])
            ->first();

        return [
            'commission_visible' => $directCommissionVisible,
            'commission' => $commission !== null ? [
                'id' => (int) $commission->id,
                'rate_bps' => (int) data_get($correction?->snapshot, 'after.rate_bps', $commission->rate_bps),
                'amount_krw' => (int) data_get($correction?->snapshot, 'after.commission_krw', $commission->amount_krw),
                'rule_snapshot' => $commission->rule_snapshot,
            ] : ($directCommission === null || ! $directCommissionVisible ? null : [
                'id' => (int) $directCommission->id,
                'rate_bps' => (int) $directCommission->rate_bps,
                'amount_krw' => (int) $directCommission->commission_amount_krw,
                'rule_snapshot' => $directCommission->rule_snapshot,
            ]),
            'settlement' => $item === null ? null : [
                'id' => (int) $item->settlement_id,
                'item_id' => (int) $item->item_id,
                'period_start' => (string) $item->period_start,
                'period_end' => (string) $item->period_end,
                'status' => (string) $item->status,
            ],
            'correction' => $correction === null ? null : [
                'delta_consumption_krw' => (int) $correction->consumption_delta_krw,
                'delta_commission_krw' => (int) $correction->commission_delta_krw,
                'eligible_period_start' => $correction->eligible_period_start->toDateString(),
                'target_settlement_id' => $correction->target_settlement_id === null ? null : (int) $correction->target_settlement_id,
                'reason' => (string) $correction->reason,
            ],
            'corrections' => $corrections->map(static fn (SettlementAdjustment $adjustment): array => [
                'id' => (int) $adjustment->id,
                'original_settlement_id' => $adjustment->original_settlement_id === null ? null : (int) $adjustment->original_settlement_id,
                'target_settlement_id' => $adjustment->target_settlement_id === null ? null : (int) $adjustment->target_settlement_id,
                'eligible_period_start' => $adjustment->eligible_period_start->toDateString(),
                'delta_consumption_krw' => (int) $adjustment->consumption_delta_krw,
                'delta_commission_krw' => (int) $adjustment->commission_delta_krw,
                'reason' => (string) $adjustment->reason,
                'actor_id' => data_get($adjustment->snapshot, 'actor_id') === null
                    ? ($adjustment->created_by === null ? null : (int) $adjustment->created_by)
                    : (int) data_get($adjustment->snapshot, 'actor_id'),
                'before' => data_get($adjustment->snapshot, 'before', []),
                'after' => data_get($adjustment->snapshot, 'after', []),
            ])->all(),
        ];
    }
}
