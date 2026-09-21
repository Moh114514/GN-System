<?php

namespace App\Modules\Settlement\Application\Services;

use App\Modules\Auth\Application\Contracts\AccessContextResolver;
use App\Modules\Settlement\Application\Contracts\OrderFinancialReader;
use App\Modules\Settlement\Infrastructure\Models\DirectOrderCommission;
use App\Modules\Settlement\Infrastructure\Models\OrderCommission;
use Illuminate\Support\Facades\DB;

final class DatabaseOrderFinancialReader implements OrderFinancialReader
{
    public function __construct(private readonly AccessContextResolver $access) {}

    public function forOrder(int $orderId): array
    {
        $commission = OrderCommission::query()->where('order_id', $orderId)->first();
        $directCommission = DirectOrderCommission::query()->where('order_id', $orderId)->where('status', 'active')->first();
        if ($commission !== null) {
            abort_unless($this->access->current()->canViewAgent((int) $commission->agent_id), 404);
        }
        if ($directCommission !== null) {
            $order = DB::table('orders')->where('id', $orderId)->first();
            abort_unless($order !== null && $this->access->current()->canViewOrder('direct', null, $order->owner_id === null ? null : (int) $order->owner_id), 404);
        }
        $item = DB::table('settlement_items')
            ->join('settlements', 'settlements.id', '=', 'settlement_items.settlement_id')
            ->where('settlement_items.order_commission_id', $commission === null ? 0 : $commission->id)
            ->select([
                'settlements.id',
                'settlements.period_start',
                'settlements.period_end',
                'settlements.status',
            ])
            ->first();

        return [
            'commission' => $commission !== null ? [
                'id' => (int) $commission->id,
                'rate_bps' => (int) $commission->rate_bps,
                'amount_krw' => (int) $commission->amount_krw,
                'rule_snapshot' => $commission->rule_snapshot,
            ] : ($directCommission === null ? null : [
                'id' => (int) $directCommission->id,
                'rate_bps' => (int) $directCommission->rate_bps,
                'amount_krw' => (int) $directCommission->commission_amount_krw,
                'rule_snapshot' => $directCommission->rule_snapshot,
            ]),
            'settlement' => $item === null ? null : [
                'id' => (int) $item->id,
                'period_start' => (string) $item->period_start,
                'period_end' => (string) $item->period_end,
                'status' => (string) $item->status,
            ],
        ];
    }
}
