<?php

namespace App\Modules\Settlement\Application\Services;

use App\Infrastructure\Time\BusinessClock;
use App\Modules\Auth\Application\Contracts\AccessContextResolver;
use App\Modules\Settlement\Application\Contracts\PaidOrderCorrectionGateway;
use App\Modules\Settlement\Application\Data\PaidOrderCorrectionData;
use App\Modules\Settlement\Infrastructure\Models\Settlement;
use App\Modules\Settlement\Infrastructure\Models\SettlementAdjustment;
use Illuminate\Support\Facades\DB;

final readonly class DatabasePaidOrderCorrectionGateway implements PaidOrderCorrectionGateway
{
    public function __construct(
        private BusinessClock $clock,
        private AccessContextResolver $access,
    ) {}

    public function record(PaidOrderCorrectionData $data): void
    {
        abort_unless($this->access->current()->isSuperAdmin(), 403);
        DB::transaction(function () use ($data): void {
            $existing = SettlementAdjustment::query()
                ->where('adjustment_key', $data->correctionKey)
                ->first();
            if ($existing !== null) {
                $sameResult = (int) data_get($existing->snapshot, 'after.amount_krw') === $data->newAmountKrw
                    && (int) data_get($existing->snapshot, 'after.commission_krw') === $data->newCommissionKrw
                    && (int) data_get($existing->snapshot, 'after.rate_bps') === $data->newRateBps
                    && (string) data_get($existing->snapshot, 'after.occurred_on') === $data->newOccurredOn->toDateString()
                    && (string) $existing->reason === trim($data->reason);
                if (! $sameResult) {
                    abort(409);
                }

                return;
            }
            $settlement = Settlement::query()->lockForUpdate()->findOrFail($data->originalSettlementId);
            if (! in_array((string) $settlement->status, ['settled', 'paid', 'reconciled'], true)) {
                abort(409);
            }

            $previousCorrection = SettlementAdjustment::query()
                ->where('source_order_id', $data->orderId)
                ->whereNotNull('original_settlement_id')
                ->latest('id')
                ->first();
            $previousCommission = $previousCorrection === null
                ? $data->oldCommissionKrw
                : (int) data_get($previousCorrection->snapshot, 'after.commission_krw', $data->oldCommissionKrw);
            $previousRate = $previousCorrection === null
                ? $data->oldRateBps
                : (int) data_get($previousCorrection->snapshot, 'after.rate_bps', $data->oldRateBps);

            $commissionDelta = $data->newCommissionKrw - $previousCommission;
            $consumptionDelta = $data->newAmountKrw - $data->oldAmountKrw;
            if ($commissionDelta === 0 && $consumptionDelta === 0) {
                return;
            }

            SettlementAdjustment::query()->create([
                'agent_id' => $data->agentId,
                'source_order_id' => $data->orderId,
                'order_commission_id' => $data->orderCommissionId,
                'original_settlement_id' => $data->originalSettlementId,
                'target_settlement_id' => null,
                'adjustment_key' => $data->correctionKey,
                'eligible_period_start' => $this->clock->now()->startOfMonth()->toDateString(),
                'consumption_delta_krw' => $consumptionDelta,
                'commission_delta_krw' => $commissionDelta,
                'reason' => trim($data->reason),
                'created_by' => $data->actorId,
                'snapshot' => [
                    'order_id' => $data->orderId,
                    'original_settlement_id' => $data->originalSettlementId,
                    'actor_id' => $data->actorId,
                    'before' => [
                        'occurred_on' => $data->oldOccurredOn->toDateString(),
                        'amount_krw' => $data->oldAmountKrw,
                        'commission_krw' => $previousCommission,
                        'rate_bps' => $previousRate,
                    ],
                    'after' => [
                        'occurred_on' => $data->newOccurredOn->toDateString(),
                        'amount_krw' => $data->newAmountKrw,
                        'commission_krw' => $data->newCommissionKrw,
                        'rate_bps' => $data->newRateBps,
                    ],
                ],
            ]);
        }, 3);
    }
}
