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
            'translator_name' => $data->translatorName,
            'owner_id' => $data->ownerId,
            'status' => 'scheduled',
            'notes' => $data->notes,
        ])->id;
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
                'meta' => ['amount_krw' => (int) $order->amount_krw, 'channel' => $order->channel],
            ];
        }

        return $events;
    }
}
