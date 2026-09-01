<?php

namespace App\Modules\Order\Application\Services;

use App\Modules\Order\Application\Contracts\CustomerOrderGateway;
use App\Modules\Order\Application\Data\CustomerAppointmentData;
use App\Modules\Order\Infrastructure\Models\Appointment;
use App\Modules\Order\Infrastructure\Models\Order;
use Carbon\CarbonImmutable;

final class DatabaseCustomerOrderGateway implements CustomerOrderGateway
{
    public function createInitialAppointment(CustomerAppointmentData $data): int
    {
        return Appointment::query()->create([
            'customer_id' => $data->customerId,
            'institution_id' => $data->institutionId,
            'scheduled_at' => $data->scheduledAt,
            'treatment_project_snapshot' => $data->projectName,
            'translator_name' => $data->translatorName,
            'owner_id' => $data->ownerId,
            'status' => 'scheduled',
            'notes' => $data->notes,
        ])->id;
    }

    /** @return array{id: int, institution_id: int, scheduled_at: string|null, owner_id: int|null, status: string}|null */
    public function latestAppointmentForCustomer(int $customerId): ?array
    {
        $appointment = Appointment::query()
            ->where('customer_id', $customerId)
            ->whereIn('status', ['scheduled', 'arrived'])
            ->orderByRaw('scheduled_at IS NULL')
            ->orderByDesc('scheduled_at')
            ->orderByDesc('id')
            ->first();

        if ($appointment === null) {
            return null;
        }

        return [
            'id' => (int) $appointment->id,
            'institution_id' => (int) $appointment->institution_id,
            'scheduled_at' => $appointment->scheduled_at === null
                ? null
                : CarbonImmutable::parse($appointment->scheduled_at)->toIso8601String(),
            'owner_id' => $appointment->owner_id === null ? null : (int) $appointment->owner_id,
            'status' => (string) $appointment->status,
        ];
    }

    /** @return array{previous: array{id: int, institution_id: int, scheduled_at: string|null, owner_id: int|null, status: string}, current: array{id: int, institution_id: int, scheduled_at: string|null, owner_id: int|null, status: string}}|null */
    public function rescheduleAppointment(int $customerId, int $appointmentId, CarbonImmutable $scheduledAt): ?array
    {
        $appointment = Appointment::query()
            ->whereKey($appointmentId)
            ->where('customer_id', $customerId)
            ->where('status', 'scheduled')
            ->lockForUpdate()
            ->first();

        if ($appointment === null) {
            return null;
        }

        $previous = $this->serializeAppointment($appointment);
        $appointment->update(['scheduled_at' => $scheduledAt]);

        return ['previous' => $previous, 'current' => $this->serializeAppointment($appointment->refresh())];
    }

    public function markAppointmentArrived(int $customerId): ?int
    {
        $appointment = Appointment::query()
            ->where('customer_id', $customerId)
            ->where('status', 'scheduled')
            ->orderByRaw('scheduled_at IS NULL')
            ->orderByDesc('scheduled_at')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        if ($appointment === null) {
            return null;
        }

        $appointment->update(['status' => 'arrived']);

        return (int) $appointment->id;
    }

    public function completeAppointmentForCustomer(int $customerId, int $institutionId): ?int
    {
        $appointment = Appointment::query()
            ->where('customer_id', $customerId)
            ->where('institution_id', $institutionId)
            ->whereIn('status', ['scheduled', 'arrived'])
            ->orderByRaw('scheduled_at IS NULL')
            ->orderByDesc('scheduled_at')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        if ($appointment === null) {
            return null;
        }

        $appointment->update(['status' => 'completed']);

        return (int) $appointment->id;
    }

    public function customerIdsForInstitution(int $institutionId): array
    {
        return array_values(array_unique([
            ...Appointment::query()->where('institution_id', $institutionId)->pluck('customer_id')->map(fn ($id): int => (int) $id)->all(),
            ...Order::query()->where('institution_id', $institutionId)->pluck('customer_id')->map(fn ($id): int => (int) $id)->all(),
        ]));
    }

    public function timelineForCustomer(int $customerId): array
    {
        $events = [];

        foreach (Appointment::query()->where('customer_id', $customerId)->orderByDesc('scheduled_at')->get() as $appointment) {
            $events[] = [
                'type' => 'appointment',
                'occurred_at' => $appointment->scheduled_at === null
                    ? $appointment->created_at?->toIso8601String()
                    : CarbonImmutable::parse($appointment->scheduled_at)->toIso8601String(),
                'title' => '预约到店',
                'content' => $appointment->notes,
                'institution_id' => (int) $appointment->institution_id,
                'owner_id' => $appointment->owner_id === null ? null : (int) $appointment->owner_id,
                'meta' => ['translator' => $appointment->translator_name, 'status' => $appointment->status],
            ];
        }

        foreach (Order::query()->where('customer_id', $customerId)->orderByDesc('completed_on')->get() as $order) {
            $events[] = [
                'type' => 'order',
                'occurred_at' => $order->completed_on === null
                    ? $order->created_at?->toIso8601String()
                    : CarbonImmutable::parse($order->completed_on)->startOfDay()->toIso8601String(),
                'title' => '消费订单',
                'content' => $order->project_name,
                'institution_id' => (int) $order->institution_id,
                'owner_id' => $order->owner_id === null ? null : (int) $order->owner_id,
                'meta' => ['amount_krw' => (int) $order->amount_krw],
            ];
        }

        return $events;
    }

    public function hasAnyOrder(int $customerId): bool
    {
        return Order::query()->where('customer_id', $customerId)->exists();
    }

    public function transferFutureAppointments(int $customerId, int $ownerId, CarbonImmutable $from): int
    {
        return Appointment::query()
            ->where('customer_id', $customerId)
            ->where('status', 'scheduled')
            ->where(function ($query) use ($from): void {
                $query->whereNull('scheduled_at')->orWhere('scheduled_at', '>=', $from);
            })
            ->update(['owner_id' => $ownerId]);
    }

    /** @return array{id: int, institution_id: int, scheduled_at: string|null, owner_id: int|null, status: string} */
    private function serializeAppointment(Appointment $appointment): array
    {
        return [
            'id' => (int) $appointment->id,
            'institution_id' => (int) $appointment->institution_id,
            'scheduled_at' => $appointment->scheduled_at === null
                ? null
                : CarbonImmutable::parse($appointment->scheduled_at)->toIso8601String(),
            'owner_id' => $appointment->owner_id === null ? null : (int) $appointment->owner_id,
            'status' => (string) $appointment->status,
        ];
    }
}
