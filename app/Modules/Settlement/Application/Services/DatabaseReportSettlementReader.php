<?php

namespace App\Modules\Settlement\Application\Services;

use App\Modules\Settlement\Application\Contracts\ReportSettlementReader;
use App\Modules\Settlement\Infrastructure\Models\OrderCommission;
use App\Modules\Settlement\Infrastructure\Models\Settlement;
use App\Modules\Settlement\Infrastructure\Models\SettlementRun;
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
            'progress' => $this->progress($asOf),
        ];
    }

    /**
     * @return array{
     *   percentage: float,
     *   settled_amount: int,
     *   review_amount: int,
     *   pending_amount: int,
     *   expected_amount: int,
     *   period_start: string,
     *   period_end: string
     * }
     */
    private function progress(CarbonImmutable $asOf): array
    {
        $run = SettlementRun::query()
            ->whereDate('period_end', '<=', $asOf->toDateString())
            ->latest('period_end')
            ->first();
        if ($run === null) {
            return [
                'percentage' => 0.0,
                'settled_amount' => 0,
                'review_amount' => 0,
                'pending_amount' => 0,
                'expected_amount' => 0,
                'period_start' => $asOf->startOfMonth()->toDateString(),
                'period_end' => $asOf->endOfMonth()->toDateString(),
            ];
        }

        $settlements = Settlement::query()->where('settlement_run_id', $run->id)->get();
        $settledAmount = (int) $settlements
            ->whereIn('status', ['settled', 'paid'])
            ->sum('total_commission_krw');
        $reviewAmount = (int) $settlements
            ->whereIn('status', ['pending_review', 'rejected'])
            ->sum('total_commission_krw');
        $pendingAmount = (int) $settlements
            ->where('status', 'approved')
            ->sum('total_commission_krw');
        $expectedAmount = (int) $settlements->sum('total_commission_krw');

        return [
            'percentage' => $expectedAmount === 0
                ? 0.0
                : round($settledAmount / $expectedAmount * 100, 1),
            'settled_amount' => $settledAmount,
            'review_amount' => $reviewAmount,
            'pending_amount' => $pendingAmount,
            'expected_amount' => $expectedAmount,
            'period_start' => $run->period_start->toDateString(),
            'period_end' => $run->period_end->toDateString(),
        ];
    }
}
