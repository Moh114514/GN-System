<?php

namespace App\Modules\Settlement\Application\Services;

use App\Modules\Settlement\Application\Contracts\SettlementImportGateway;
use App\Modules\Settlement\Application\Data\CommissionImportData;
use App\Modules\Settlement\Application\Data\SettlementImportData;
use App\Modules\Settlement\Infrastructure\Models\OrderCommission;
use App\Modules\Settlement\Infrastructure\Models\Settlement;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

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
                'snapshot' => ['source' => 'historical_import'],
                'import_batch_id' => $data->importBatchId,
            ],
        );

        return $settlement->id;
    }

    public function deleteImportedByBatch(string $batchId): int
    {
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

        return $blockers;
    }
}
