<?php

namespace App\Modules\Order\Application\Services;

use App\Modules\Order\Application\Contracts\ReminderSourceReader;
use App\Modules\Order\Application\Data\ReminderAppointmentData;
use App\Modules\Order\Application\Data\ReminderOrderSourceData;
use App\Modules\Order\Infrastructure\Models\Appointment;
use App\Modules\Order\Infrastructure\Models\Order;
use Carbon\CarbonImmutable;

final class DatabaseReminderSourceReader implements ReminderSourceReader
{
    public function appointments(): array
    {
        return Appointment::query()
            ->whereNotNull('scheduled_at')
            ->orderBy('id')
            ->get()
            ->map(fn (Appointment $appointment): ReminderAppointmentData => new ReminderAppointmentData(
                id: (int) $appointment->id,
                customerId: (int) $appointment->customer_id,
                scheduledAt: CarbonImmutable::parse($appointment->scheduled_at),
                ownerId: $appointment->owner_id === null ? null : (int) $appointment->owner_id,
                status: (string) $appointment->status,
            ))
            ->all();
    }

    public function completedOrders(): array
    {
        return Order::query()
            ->where('status', 'completed')
            ->whereNotNull('completed_on')
            ->orderBy('id')
            ->get()
            ->map(fn (Order $order): ReminderOrderSourceData => new ReminderOrderSourceData(
                id: (int) $order->id,
                customerId: (int) $order->customer_id,
                projectName: (string) $order->project_name,
                completedOn: CarbonImmutable::parse($order->completed_on),
                ownerId: $order->owner_id === null ? null : (int) $order->owner_id,
            ))
            ->all();
    }
}
