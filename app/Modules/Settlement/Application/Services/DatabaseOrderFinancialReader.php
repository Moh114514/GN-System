<?php

namespace App\Modules\Settlement\Application\Services;

use App\Modules\Settlement\Application\Contracts\OrderFinancialReader;
use App\Modules\Settlement\Infrastructure\Models\OrderCommission;
use Illuminate\Support\Facades\DB;

final class DatabaseOrderFinancialReader implements OrderFinancialReader
{
    public function forOrder(int $orderId): array
    {
        $commission = OrderCommission::query()->where('order_id', $orderId)->first();
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
            'commission' => $commission === null ? null : [
                'id' => (int) $commission->id,
                'rate_bps' => (int) $commission->rate_bps,
                'amount_krw' => (int) $commission->amount_krw,
                'rule_snapshot' => $commission->rule_snapshot,
            ],
            'settlement' => $item === null ? null : [
                'id' => (int) $item->id,
                'period_start' => (string) $item->period_start,
                'period_end' => (string) $item->period_end,
                'status' => (string) $item->status,
            ],
        ];
    }
}
