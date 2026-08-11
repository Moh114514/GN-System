<?php

namespace App\Modules\Settlement\Application\Services;

use App\Modules\Settlement\Application\Contracts\SettlementImportGateway;
use App\Modules\Settlement\Application\Data\CommissionImportData;
use App\Modules\Settlement\Application\Data\SettlementImportData;
use App\Modules\Settlement\Infrastructure\Models\OrderCommission;
use App\Modules\Settlement\Infrastructure\Models\Settlement;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class DatabaseSettlementImportGateway implements SettlementImportGateway
{
    public function recordCommission(CommissionImportData $data): int
    {
        $commission = OrderCommission::query()->updateOrCreate(
            ['order_id' => $data->orderId],
            [
                'agent_id' => $data->agentId,
                'rate_bps' => $data->rateBps,
                'amount_krw' => $data->amountKrw,
                'rule_snapshot' => $data->ruleSnapshot,
                'override_reason' => $data->overrideReason,
                'import_batch_id' => $data->importBatchId,
            ],
        );

        return $commission->id;
    }

    public function upsertSettlement(SettlementImportData $data): int
    {
        $settlement = Settlement::query()->updateOrCreate(
            [
                'agent_id' => $data->agentId,
                'period_start' => $data->periodStart,
                'period_end' => $data->periodEnd,
            ],
            [
                'settled_on' => $data->settledOn,
                'exchange_rate_krw_per_cny' => $data->exchangeRateKrwPerCny,
                'total_consumption_krw' => $data->totalConsumptionKrw,
                'total_commission_krw' => $data->totalCommissionKrw,
                'payout_amount_cny_fen' => $data->payoutAmountCnyFen,
                'status' => $data->status,
                'generation_status' => 'not_applicable',
                'generated_at' => null,
                'item_count' => 0,
                'snapshot' => [
                    'source' => 'historical_import',
                    ...($data->agentSnapshot === null ? [] : ['agent' => $data->agentSnapshot]),
                ],
                'import_batch_id' => $data->importBatchId,
            ],
        );

        return $settlement->id;
    }

    public function materializeHistoricalItems(string $importBatchId): void
    {
        $settlements = Settlement::query()->where('import_batch_id', $importBatchId)->get();
        foreach ($settlements as $settlement) {
            $rows = DB::table('order_commissions as commission')
                ->join('orders as order', 'order.id', '=', 'commission.order_id')
                ->where('commission.import_batch_id', $importBatchId)
                ->where('commission.agent_id', $settlement->agent_id)
                ->whereBetween('order.completed_on', [$settlement->period_start, $settlement->period_end])
                ->orderBy('commission.id')
                ->get([
                    'commission.id as order_commission_id',
                    'commission.amount_krw as commission_krw',
                    'commission.rule_snapshot',
                    'order.id as order_id',
                    'order.customer_id',
                    'order.project_name',
                    'order.completed_on',
                    'order.amount_krw as consumption_krw',
                ]);
            $consumption = (int) $rows->sum('consumption_krw');
            $commission = (int) $rows->sum('commission_krw');
            if ($consumption !== (int) $settlement->total_consumption_krw || $commission !== (int) $settlement->total_commission_krw) {
                if ($rows->isNotEmpty() || (int) $settlement->total_consumption_krw !== 0 || (int) $settlement->total_commission_krw !== 0) {
                    throw new RuntimeException("历史月结 {$settlement->id} 与明细金额不一致，已阻止建立结算明细。");
                }
            }
            foreach ($rows as $row) {
                $ruleSnapshot = is_string($row->rule_snapshot) ? json_decode($row->rule_snapshot, true, 512, JSON_THROW_ON_ERROR) : ($row->rule_snapshot ?? []);
                DB::table('settlement_items')->updateOrInsert(
                    ['settlement_id' => $settlement->id, 'order_commission_id' => $row->order_commission_id],
                    [
                        'consumption_krw' => $row->consumption_krw,
                        'commission_krw' => $row->commission_krw,
                        'rule_snapshot' => json_encode([
                            ...$ruleSnapshot,
                            'source' => 'historical_import',
                            'order' => ['id' => $row->order_id, 'customer_id' => $row->customer_id, 'project_name' => $row->project_name, 'completed_on' => $row->completed_on],
                        ], JSON_THROW_ON_ERROR),
                        'import_batch_id' => $importBatchId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );
            }
            $settlement->update(['item_count' => $rows->count()]);
        }
    }

    public function deleteImportedByBatch(string $batchId): int
    {
        $referenced = DB::table('settlement_run_members')
            ->join('settlements', 'settlements.id', '=', 'settlement_run_members.settlement_id')
            ->where('settlements.import_batch_id', $batchId)
            ->pluck('settlements.id');
        if ($referenced->isNotEmpty()) {
            throw new RuntimeException('该历史月结已被月结批次引用，请先处理相关月结关系。');
        }
        $deleted = Settlement::query()->where('import_batch_id', $batchId)->delete();
        OrderCommission::query()->where('import_batch_id', $batchId)->delete();

        return $deleted;
    }

    public function rollbackBlockers(string $batchId, DateTimeInterface $completedAt): array
    {
        $blockers = [];
        foreach (['settlements', 'order_commissions'] as $table) {
            $ids = DB::table($table)
                ->where('import_batch_id', $batchId)
                ->where('updated_at', '>', $completedAt)
                ->pluck('id');

            foreach ($ids as $id) {
                $blockers[] = "{$table}:{$id}";
            }
        }
        $referenced = DB::table('settlement_run_members')
            ->join('settlements', 'settlements.id', '=', 'settlement_run_members.settlement_id')
            ->where('settlements.import_batch_id', $batchId)
            ->pluck('settlement_run_members.id');
        foreach ($referenced as $id) {
            $blockers[] = "settlement_run_members:{$id}";
        }

        return $blockers;
    }
}
