<?php

namespace App\Modules\Reminder\Application\Services;

use App\Infrastructure\Time\BusinessClock;
use App\Modules\Reminder\Application\Contracts\AppointmentReminderGateway;
use App\Modules\Reminder\Infrastructure\Models\Reminder;
use App\Modules\Reminder\Infrastructure\Models\ReminderEvent;
use Carbon\CarbonImmutable;

final readonly class DatabaseAppointmentReminderGateway implements AppointmentReminderGateway
{
    public function __construct(private BusinessClock $clock) {}

    public function syncForAppointment(
        int $appointmentId,
        int $customerId,
        ?int $assignedTo,
        CarbonImmutable $scheduledAt,
    ): int {
        $dueAt = $scheduledAt->startOfDay()->subDay()->setTime(18, 0);
        $contentKey = 'reminders.system_reminders.arrival_previous_day';
        $localizedContent = [
            'title' => ['key' => $contentKey.'.title', 'parameters' => []],
            'suggestion' => ['key' => $contentKey.'.suggestion', 'parameters' => []],
            'notes' => [['key' => $contentKey.'.expected_arrival', 'parameters' => ['scheduled_at' => $scheduledAt->format('Y-m-d H:i')]]],
        ];

        $this->cancelStalePending($appointmentId, $dueAt);
        if ($dueAt->isBefore($this->clock->now())) {
            return 0;
        }

        $dedupeKey = hash('sha256', "appointment:{$appointmentId}:arrival_previous_day:{$dueAt->toIso8601String()}");
        $reminder = Reminder::query()->where('dedupe_key', $dedupeKey)->first();
        $attributes = [
            'customer_id' => $customerId,
            'appointment_id' => $appointmentId,
            'assigned_to' => $assignedTo,
            'source_type' => 'system',
            'reminder_type' => 'appointment',
            'title' => (string) __($contentKey.'.title'),
            'suggestion' => (string) __($contentKey.'.suggestion'),
            'notes' => (string) __($contentKey.'.expected_arrival', ['scheduled_at' => $scheduledAt->format('Y-m-d H:i')]),
            'localized_content' => $localizedContent,
            'priority' => 1,
            'due_at' => $dueAt,
            'status' => 'pending',
            'notification_status' => 'pending',
        ];
        if ($reminder === null) {
            $reminder = Reminder::query()->create(['dedupe_key' => $dedupeKey, ...$attributes]);
            $this->event($reminder, 'generated', ['source' => 'appointment', 'due_at' => $dueAt->toIso8601String()]);

            return 1;
        }

        if ($reminder->status === 'cancelled') {
            $reminder->update($attributes);
            $this->event($reminder, 'reactivated', ['source' => 'appointment', 'due_at' => $dueAt->toIso8601String()]);

            return 1;
        }

        if (in_array($reminder->status, ['pending', 'snoozed', 'transferred'], true)) {
            $reminder->update([
                ...$attributes,
                'status' => $reminder->status,
                'notification_status' => $reminder->notification_status,
            ]);
        }

        return 0;
    }

    public function cancelForAppointment(int $appointmentId, ?int $actorId, string $reason): int
    {
        $reminders = Reminder::query()
            ->where('appointment_id', $appointmentId)
            ->whereIn('status', ['pending', 'snoozed', 'transferred'])
            ->lockForUpdate()
            ->get();
        foreach ($reminders as $reminder) {
            $before = $reminder->status;
            $reminder->update(['status' => 'cancelled', 'notification_status' => 'cancelled']);
            $this->event($reminder, 'cancelled', ['reason' => $reason, 'before' => $before, 'after' => 'cancelled'], $actorId);
        }

        return $reminders->count();
    }

    private function cancelStalePending(int $appointmentId, CarbonImmutable $dueAt): void
    {
        Reminder::query()
            ->where('appointment_id', $appointmentId)
            ->whereIn('status', ['pending', 'snoozed', 'transferred'])
            ->where('notification_status', '!=', 'sent')
            ->where('due_at', '!=', $dueAt)
            ->lockForUpdate()
            ->get()
            ->each(function (Reminder $reminder) use ($dueAt): void {
                $before = $reminder->due_at->toIso8601String();
                $reminder->update(['status' => 'cancelled', 'notification_status' => 'cancelled']);
                $this->event($reminder, 'cancelled', ['reason' => 'appointment_rescheduled', 'before_due_at' => $before, 'new_due_at' => $dueAt->toIso8601String()]);
            });
    }

    /** @param array<string, mixed> $properties */
    private function event(Reminder $reminder, string $event, array $properties, ?int $actorId = null): void
    {
        ReminderEvent::query()->create([
            'reminder_id' => $reminder->id,
            'actor_id' => $actorId,
            'event' => $event,
            'properties' => $properties,
            'occurred_at' => $this->clock->now(),
        ]);
    }
}
