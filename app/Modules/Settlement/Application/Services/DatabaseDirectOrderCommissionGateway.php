<?php

namespace App\Modules\Settlement\Application\Services;

use App\Infrastructure\Time\BusinessClock;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Auth\Application\Contracts\AccessContextResolver;
use App\Modules\Settlement\Application\Contracts\DirectCommissionConfigurationGateway;
use App\Modules\Settlement\Application\Contracts\DirectOrderCommissionGateway;
use App\Modules\Settlement\Application\Data\DirectOrderCommissionData;
use App\Modules\Settlement\Infrastructure\Models\DirectOrderCommission;
use DomainException;

final readonly class DatabaseDirectOrderCommissionGateway implements DirectOrderCommissionGateway
{
    public function __construct(
        private DirectCommissionConfigurationGateway $configuration,
        private AccessContextResolver $access,
        private AuditRecorder $audit,
        private BusinessClock $clock,
    ) {}

    public function recordForCompletedOrder(DirectOrderCommissionData $data): int
    {
        $existing = DirectOrderCommission::query()
            ->where('order_id', $data->orderId)
            ->where('status', 'active')
            ->first();
        if ($existing !== null) {
            return (int) $existing->id;
        }

        $rate = $this->configuration->rateForDate($data->completedAt);
        if ($rate === null) {
            throw new DomainException(__('settlements.direct_commission.errors.rate_missing'));
        }
        $rateBps = (int) $rate['rate_bps'];
        $commissionAmount = intdiv($data->orderAmountKrw * $rateBps, 10000);
        $commission = DirectOrderCommission::query()->create([
            'order_id' => $data->orderId,
            'owner_id' => $data->ownerId,
            'rate_bps' => $rateBps,
            'order_amount_krw' => $data->orderAmountKrw,
            'commission_amount_krw' => $commissionAmount,
            'completed_at' => $data->completedAt,
            'status' => 'active',
            'rule_snapshot' => [
                'rate_id' => $rate['id'],
                'rate_bps' => $rateBps,
                'effective_from' => $rate['effective_from'],
                'effective_until' => $rate['effective_until'],
                'order_amount_krw' => $data->orderAmountKrw,
                'commission_amount_krw' => $commissionAmount,
                'owner_id_at_completion' => $data->ownerId,
                'completed_at' => $data->completedAt->toIso8601String(),
                'direct_channel' => $data->directChannel,
            ],
        ]);
        $this->audit->record(
            description: __('settlements.direct_commission.audit.commission_created'),
            properties: $commission->rule_snapshot,
            causerId: $data->actorId,
            subject: $commission,
            logName: 'direct-commission',
            event: 'calculated',
            ipAddress: $data->ipAddress,
        );

        return (int) $commission->id;
    }

    public function voidForOrder(int $orderId, int $actorId, string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException(__('settlements.direct_commission.errors.void_reason_required'));
        }
        DirectOrderCommission::query()
            ->where('order_id', $orderId)
            ->where('status', 'active')
            ->lockForUpdate()
            ->get()
            ->each(function (DirectOrderCommission $commission) use ($actorId, $reason): void {
                $commission->update([
                    'status' => 'voided',
                    'voided_at' => $this->clock->now(),
                    'voided_by' => $actorId,
                    'void_reason' => $reason,
                ]);
                $this->audit->record(
                    description: __('settlements.direct_commission.audit.commission_voided'),
                    properties: ['reason' => $reason, 'commission_id' => $commission->id],
                    causerId: $actorId,
                    subject: $commission,
                    logName: 'direct-commission',
                    event: 'voided',
                    ipAddress: null,
                );
            });
    }

    public function recordsForViewer(): array
    {
        $context = $this->access->current();
        abort_unless($context->isSuperAdmin() || $context->isDirectCustomerManager(), 403);
        $query = DirectOrderCommission::query()->orderByDesc('completed_at')->orderByDesc('id');
        if (! $context->isSuperAdmin()) {
            $query->where('owner_id', $context->userId);
        }

        return $query->get()->map(fn (DirectOrderCommission $commission): array => [
            'id' => (int) $commission->id,
            'order_id' => (int) $commission->order_id,
            'owner_id' => $commission->owner_id === null ? null : (int) $commission->owner_id,
            'rate_bps' => (int) $commission->rate_bps,
            'order_amount_krw' => (int) $commission->order_amount_krw,
            'commission_amount_krw' => (int) $commission->commission_amount_krw,
            'completed_at' => $commission->completed_at->format('Y-m-d H:i'),
            'status' => (string) $commission->status,
            'direct_channel' => data_get($commission->rule_snapshot, 'direct_channel.name'),
        ])->all();
    }
}
