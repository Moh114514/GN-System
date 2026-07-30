<?php

namespace App\Modules\Settlement\Application\Services;

use App\Modules\Settlement\Application\Contracts\ReportSettlementReader;
use App\Modules\Settlement\Infrastructure\Models\OrderCommission;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class DatabaseReportSettlementReader implements ReportSettlementReader
{
    public function dashboard(array $orderMonths, CarbonImmutable $asOf): array
    {
        $orderIds = array_map('intval', array_keys($orderMonths));
        $commissions = $orderIds === []
            ? collect()
            : OrderCommission::query()->whereIn('order_id', $orderIds)->get();
        $monthly = [];
        foreach ($commissions as $commission) {
            $month = $orderMonths[(int) $commission->order_id] ?? null;
            if ($month !== null && $month !== '') {
                $monthly[$month] = ($monthly[$month] ?? 0) + (int) $commission->amount_krw;
            }
        }
        ksort($monthly);

        $settledCommissionIds = DB::table('settlement_items as item')
            ->join('settlements as settlement', 'settlement.id', '=', 'item.settlement_id')
            ->whereIn('settlement.status', ['settled', 'paid'])
            ->where(function ($query) use ($asOf): void {
                $query->where('settlement.confirmed_at', '<=', $asOf)
                    ->orWhere('settlement.settled_on', '<=', $asOf->toDateString());
            })
            ->pluck('item.order_commission_id');

        return [
            'promotion_fee' => (int) $commissions->sum('amount_krw'),
            'pending_settlement' => (int) OrderCommission::query()
                ->where('created_at', '<=', $asOf)
                ->whereNotIn('id', $settledCommissionIds)
                ->sum('amount_krw'),
            'agent_ranking' => $commissions->groupBy('agent_id')
                ->map(fn ($items, $agentId): array => [
                    'agent_id' => (int) $agentId,
                    'value' => (int) $items->sum('amount_krw'),
                ])->sortByDesc('value')->values()->take(10)->all(),
            'monthly_promotion' => collect($monthly)
                ->map(fn ($value, $key): array => ['key' => (string) $key, 'value' => (int) $value])
                ->values()->all(),
        ];
    }
}
