<?php

namespace App\Modules\Order\Application\Services;

use App\Infrastructure\Time\BusinessClock;
use App\Models\User;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Order\Infrastructure\Models\Appointment;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

final readonly class LegacyAppointmentArrivalBackfill
{
    public function __construct(private AuditRecorder $audit, private BusinessClock $clock) {}

    public function record(int $appointmentId, CarbonImmutable $arrivedAt, int $actorId, string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 2000 || $arrivedAt->gt($this->clock->now())) {
            throw new DomainException(__('orders.errors.legacy_arrival_invalid'));
        }
        abort_unless(User::query()->whereKey($actorId)->where('is_super_admin', true)->where('is_active', true)->exists(), 403, __('orders.errors.legacy_arrival_admin_required'));

        DB::transaction(function () use ($appointmentId, $arrivedAt, $actorId, $reason): void {
            $appointment = Appointment::query()->lockForUpdate()->findOrFail($appointmentId);
            if (! in_array($appointment->status, ['arrived', 'completed'], true) || $appointment->arrived_at !== null) {
                throw new DomainException(__('orders.errors.legacy_arrival_locked'));
            }
            $appointment->update(['arrived_at' => $arrivedAt->setTimezone('Asia/Shanghai')]);
            $this->audit->record(
                description: __('orders.audit.legacy_arrival_backfilled'),
                properties: [
                    'appointment_id' => $appointmentId,
                    'customer_id' => (int) $appointment->customer_id,
                    'institution_id' => (int) $appointment->institution_id,
                    'before' => ['arrived_at' => null],
                    'after' => ['arrived_at' => $arrivedAt->setTimezone('Asia/Shanghai')->toIso8601String()],
                    'reason' => $reason,
                ],
                causerId: $actorId,
                subject: $appointment,
                logName: 'order',
                event: 'legacy_arrival_backfilled',
                messageKey: 'orders.audit.legacy_arrival_backfilled',
            );
        }, 3);
    }
}
