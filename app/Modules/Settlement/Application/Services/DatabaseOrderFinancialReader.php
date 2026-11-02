<?php

namespace App\Modules\Settlement\Application\Services;

use App\Modules\Auth\Application\Contracts\AccessContextResolver;
use App\Modules\Customer\Application\Contracts\CustomerOrderReferenceReader;
use App\Modules\Settlement\Application\Contracts\OrderFinancialReader;
use App\Modules\Settlement\Infrastructure\Models\DirectOrderCommission;
use App\Modules\Settlement\Infrastructure\Models\OrderCommission;
use App\Modules\Settlement\Infrastructure\Models\SettlementAdjustment;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class DatabaseOrderFinancialReader implements OrderFinancialReader
{
    public function __construct(
        private readonly AccessContextResolver $access,
        private readonly CustomerOrderReferenceReader $customers,
    ) {}

    public function forOrder(int $orderId, int $customerId, ?int $currentOrderAmountKrw = null): array
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
            || $context->hasOrganizationWideReadAccess()
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
                'settlement_items.consumption_krw',
                'settlement_items.commission_krw',
            ])
            ->first();

        $snapshotState = $commission === null
            ? ['valid' => false, 'issue' => 'missing', 'rule_source' => null, 'rule_id' => null, 'effective_month' => null]
            : $this->commissionSnapshotState($commission->rule_snapshot, $commission->rate_bps, $commission->amount_krw, $item, $corrections, $currentOrderAmountKrw);
        $effectiveAfter = $correction === null ? null : data_get($correction->snapshot, 'after');
        $effectiveRate = (int) data_get($effectiveAfter, 'rate_bps', $commission === null ? 0 : $commission->rate_bps);
        $effectiveCommission = (int) data_get($effectiveAfter, 'commission_krw', $commission === null ? 0 : $commission->amount_krw);
        $effectiveOrderAmount = (int) data_get($effectiveAfter, 'amount_krw', data_get($commission?->rule_snapshot, 'order_amount_krw', 0));

        return [
            'commission_visible' => $directCommissionVisible,
            'commission' => $commission !== null ? [
                'id' => (int) $commission->id,
                'rate_bps' => $effectiveRate,
                'amount_krw' => $effectiveCommission,
                'order_amount_krw' => $effectiveOrderAmount,
                'rule_source' => data_get($effectiveAfter, 'rule_source', $snapshotState['rule_source']),
                'rule_id' => data_get($effectiveAfter, 'rule_id', $snapshotState['rule_id']),
                'effective_month' => data_get($effectiveAfter, 'rule_effective_month', $snapshotState['effective_month']),
                'snapshot_valid' => $snapshotState['valid'],
                'snapshot_issue' => $snapshotState['issue'],
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

    /**
     * @param  array<string, mixed>|null  $ruleSnapshot
     * @param  Collection<int, SettlementAdjustment>  $corrections
     * @return array{valid: bool, issue: string|null, rule_source: string|null, rule_id: int|null, effective_month: string|null}
     */
    private function commissionSnapshotState(?array $ruleSnapshot, int $rateBps, int $commissionKrw, ?object $settlementItem, Collection $corrections, ?int $currentOrderAmountKrw): array
    {
        $ruleSource = data_get($ruleSnapshot, 'rule_source', data_get($ruleSnapshot, 'source'));
        $ruleId = data_get($ruleSnapshot, 'rule_id');
        $effectiveMonth = data_get($ruleSnapshot, 'rule_effective_month');
        $snapshotRate = data_get($ruleSnapshot, 'rate_bps');
        $snapshotAmount = data_get($ruleSnapshot, 'order_amount_krw');
        $snapshotCommission = data_get($ruleSnapshot, 'commission_amount_krw');
        $firstCorrection = $corrections->first();
        $baseAmount = $settlementItem !== null
            ? (int) $settlementItem->consumption_krw
            : (int) data_get($firstCorrection?->snapshot, 'before.amount_krw', $currentOrderAmountKrw ?? $snapshotAmount ?? 0);
        $baseCommission = $settlementItem !== null
            ? (int) $settlementItem->commission_krw
            : $commissionKrw;

        $historicalImport = $ruleSource === 'historical_import';
        $snapshotAmount ??= $baseAmount;
        $snapshotCommission ??= $baseCommission;
        $valid = is_string($ruleSource)
            && $ruleSource !== ''
            && ($historicalImport || (is_numeric($ruleId) && (int) $ruleId > 0))
            && ($historicalImport || (is_string($effectiveMonth) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveMonth) === 1))
            && is_numeric($snapshotRate)
            && (int) $snapshotRate === $rateBps
            && is_numeric($snapshotAmount)
            && (int) $snapshotAmount === $baseAmount
            && is_numeric($snapshotCommission)
            && (int) $snapshotCommission === $baseCommission
            && $this->commissionAmount($baseAmount, $rateBps) === $baseCommission;

        $lastAfter = $corrections->last() === null ? null : data_get($corrections->last()->snapshot, 'after');
        if ($valid && $lastAfter !== null) {
            $latestAmount = data_get($lastAfter, 'amount_krw');
            $latestCommission = data_get($lastAfter, 'commission_krw');
            $latestRate = data_get($lastAfter, 'rate_bps');
            $latestMonth = data_get($lastAfter, 'rule_effective_month');
            $valid = is_numeric($latestAmount)
                && is_numeric($latestCommission)
                && is_numeric($latestRate)
                && is_string(data_get($lastAfter, 'rule_source'))
                && (string) data_get($lastAfter, 'rule_source') !== ''
                && (data_get($lastAfter, 'rule_source') === 'historical_import'
                    || (is_numeric(data_get($lastAfter, 'rule_id')) && (int) data_get($lastAfter, 'rule_id') > 0))
                && (data_get($lastAfter, 'rule_source') === 'historical_import'
                    || (is_string($latestMonth) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $latestMonth) === 1))
                && $this->commissionAmount((int) $latestAmount, (int) $latestRate) === (int) $latestCommission;
        }

        if ($valid && $currentOrderAmountKrw !== null) {
            $effectiveAmount = $lastAfter === null ? $baseAmount : (int) data_get($lastAfter, 'amount_krw', 0);
            $valid = $effectiveAmount === $currentOrderAmountKrw;
        }

        return [
            'valid' => $valid,
            'issue' => $valid ? null : 'incomplete_or_inconsistent',
            'rule_source' => is_string($ruleSource) ? $ruleSource : null,
            'rule_id' => is_numeric($ruleId) ? (int) $ruleId : null,
            'effective_month' => is_string($effectiveMonth) ? $effectiveMonth : null,
        ];
    }

    private function commissionAmount(int $orderAmountKrw, int $rateBps): int
    {
        return BigDecimal::of($orderAmountKrw)
            ->multipliedBy($rateBps)
            ->dividedBy(10000, 0, RoundingMode::HalfUp)
            ->toInt();
    }
}
