<?php

namespace App\Modules\Reminder\Application\Contracts;

use Carbon\CarbonImmutable;

interface AppointmentReminderGateway
{
    public function syncForAppointment(
        int $appointmentId,
        int $customerId,
        ?int $assignedTo,
        CarbonImmutable $scheduledAt,
    ): int;

    public function cancelForAppointment(int $appointmentId, ?int $actorId, string $reason): int;
}
