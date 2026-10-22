<?php

namespace App\Modules\Order\Application\Services;

use App\Infrastructure\Time\BusinessClock;
use App\Modules\Order\Application\Contracts\CustomerOrderGateway;
use App\Modules\Order\Application\Data\CustomerAppointmentData;
use App\Modules\Order\Infrastructure\Models\Appointment;
use App\Modules\Order\Infrastructure\Models\Order;
use Carbon\CarbonImmutable;

final class DatabaseCustomerOrderGateway implements CustomerOrderGateway
{
    public function __construct(private readonly BusinessClock $clock) {}

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

    /** @return array{id: int, institution_id: int, scheduled_at: string|null, owner_id: int|null, status: string, arrived_at: string|null}|null */
    public function latestAppointmentForCustomer(int $customerId): ?array
    {
        $appointment = Appointment::query()
            ->where('customer_id', $customerId)
            ->whereIn('status', ['scheduled', 'arrived', 'completed'])
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
            'arrived_at' => $appointment->arrived_at === null
                ? null
                : CarbonImmutable::parse($appointment->arrived_at)->toIso8601String(),
        ];
    }

    /** @return array{id: int, institution_id: int, scheduled_at: string|null, owner_id: int|null, status: string, arrived_at: string|null, has_order: bool}|null */
    public function currentAppointmentForRegistration(int $customerId): ?array
    {
        $appointment = Appointment::query()
            ->where('customer_id', $customerId)
            ->where(function ($query): void {
                $query->whereIn('status', ['scheduled', 'arrived'])
                    ->orWhere(function ($completed): void {
                        $completed->where('status', 'completed')
                            ->whereNotNull('arrived_at')
                            ->whereExists(fn ($orders) => $orders->selectRaw('1')
                                ->from('orders')
                                ->whereColumn('orders.appointment_id', 'appointments.id'));
                    });
            })
            ->orderByDesc('id')
            ->first();

        if ($appointment === null) {
            return null;
        }

        $serialized = $this->serializeAppointment($appointment);
        $serialized['has_order'] = Order::query()->where('appointment_id', $appointment->id)->exists();

        return $serialized;
    }

    public function registrationVisit(int $customerId, int $appointmentId, int $institutionId): ?array
    {
        $appointment = Appointment::query()
            ->whereKey($appointmentId)
            ->where('customer_id', $customerId)
            ->where('institution_id', $institutionId)
            ->whereIn('status', ['arrived', 'completed'])
            ->whereNotNull('arrived_at')
            ->lockForUpdate()
            ->first();
        if ($appointment === null) {
            return null;
        }
        $hasOrder = Order::query()->where('appointment_id', $appointmentId)->exists();
        if ($appointment->status === 'completed' && ! $hasOrder) {
            return null;
        }

        return [
            'id' => (int) $appointment->id,
            'institution_id' => (int) $appointment->institution_id,
            'status' => (string) $appointment->status,
            'arrived_at' => CarbonImmutable::parse($appointment->arrived_at)->toIso8601String(),
            'has_order' => $hasOrder,
        ];
    }

    /** @return array{previous: array{id: int, institution_id: int, scheduled_at: string|null, owner_id: int|null, status: string, arrived_at: string|null}, current: array{id: int, institution_id: int, scheduled_at: string|null, owner_id: int|null, status: string, arrived_at: string|null}}|null */
    public function rescheduleAppointment(int $customerId, int $appointmentId, CarbonImmutable $scheduledAt): ?array
    {
        $appointment = Appointment::query()
            ->whereKey($appointmentId)
            ->where('customer_id', $customerId)
            ->whereIn('status', ['scheduled', 'arrived', 'completed'])
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
            ->whereIn('status', ['scheduled', 'completed'])
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        if ($appointment === null) {
            return null;
        }

        if ($appointment->status === 'completed') {
            $newVisit = Appointment::query()->create([
                'customer_id' => $appointment->customer_id,
                'institution_id' => $appointment->institution_id,
                'scheduled_at' => null,
                'treatment_project_snapshot' => $appointment->treatment_project_snapshot,
                'translator_name' => $appointment->translator_name,
                'owner_id' => $appointment->owner_id,
                'status' => 'arrived',
                'arrived_at' => $this->clock->now(),
                'notes' => $appointment->notes,
            ]);

            return (int) $newVisit->id;
        }

        $appointment->update(['status' => 'arrived', 'arrived_at' => $this->clock->now()]);

        return (int) $appointment->id;
    }

    /** @return array{id: int, institution_id: int, scheduled_at: string|null, owner_id: int|null, status: string, arrived_at: string|null}|null */
    public function markAppointmentScheduled(int $customerId): ?array
    {
        $appointment = Appointment::query()
            ->where('customer_id', $customerId)
            ->whereIn('status', ['arrived', 'completed'])
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        if ($appointment === null) {
            return null;
        }

        if ($appointment->status === 'completed') {
            $appointment = Appointment::query()->create([
                'customer_id' => $appointment->customer_id,
                'institution_id' => $appointment->institution_id,
                'scheduled_at' => null,
                'treatment_project_snapshot' => $appointment->treatment_project_snapshot,
                'translator_name' => $appointment->translator_name,
                'owner_id' => $appointment->owner_id,
                'status' => 'scheduled',
                'notes' => $appointment->notes,
            ]);
        } else {
            $appointment->update(['status' => 'scheduled', 'arrived_at' => null]);
        }

        return $this->serializeAppointment($appointment->refresh());
    }

    public function completeAppointmentForCustomer(int $customerId, int $institutionId, ?int $appointmentId = null): ?int
    {
        $appointment = Appointment::query()
            ->when($appointmentId !== null, fn ($query) => $query->whereKey($appointmentId))
            ->where('customer_id', $customerId)
            ->where('institution_id', $institutionId)
            ->whereIn('status', ['scheduled', 'arrived', 'completed'])
            ->orderByRaw('scheduled_at IS NULL')
            ->orderByDesc('scheduled_at')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        if ($appointment === null) {
            return null;
        }

        if ($appointment->status !== 'completed') {
            $appointment->update(['status' => 'completed']);
        }

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

    /** @return array{id: int, institution_id: int, scheduled_at: string|null, owner_id: int|null, status: string, arrived_at: string|null} */
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
            'arrived_at' => $appointment->arrived_at === null
                ? null
                : CarbonImmutable::parse($appointment->arrived_at)->toIso8601String(),
        ];
    }
}
