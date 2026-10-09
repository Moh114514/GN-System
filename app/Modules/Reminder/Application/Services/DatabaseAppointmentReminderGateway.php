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
        $now = $this->clock->now();
        $isFutureAppointment = $scheduledAt->isAfter($now);
        $plans = [
            [
                'key' => 'arrival_previous_day',
                'content_key' => 'reminders.system_reminders.arrival_previous_day',
                'planned_due_at' => $scheduledAt->startOfDay()->subDay()->setTime(18, 0),
            ],
            [
                'key' => 'arrival_same_day',
                'content_key' => 'reminders.system_reminders.arrival_today',
                'planned_due_at' => $scheduledAt->startOfDay()->setTime(9, 0),
            ],
        ];
        $specs = [];
        foreach ($plans as $plan) {
            if (! $isFutureAppointment) {
                continue;
            }
            $plannedDueAt = $plan['planned_due_at'];
            $isCompensation = $plannedDueAt->isBefore($now);
            $dueAt = $isCompensation ? $now : $plannedDueAt;
            $dueKey = $isCompensation ? 'compensation' : $dueAt->toIso8601String();
            $specs[] = [
                'key' => $plan['key'],
                'content_key' => $plan['content_key'],
                'due_at' => $dueAt,
                'dedupe_key' => hash('sha256', "appointment:{$appointmentId}:{$plan['key']}:{$dueKey}"),
            ];
        }
        $this->cancelStalePending($appointmentId, array_column($specs, 'dedupe_key'));
        if (! $isFutureAppointment) {
            return 0;
        }

        $created = 0;
        foreach ($specs as $spec) {
            $created += $this->syncReminder(
                appointmentId: $appointmentId,
                customerId: $customerId,
                assignedTo: $assignedTo,
                scheduledAt: $scheduledAt,
                dueAt: $spec['due_at'],
                dedupeKey: $spec['dedupe_key'],
                contentKey: $spec['content_key'],
                includeExpectedArrival: $spec['key'] === 'arrival_previous_day',
            );
        }

        return $created;
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
            $reminder->update([
                'status' => 'cancelled',
                'notification_status' => $reminder->notification_status === 'sent' ? 'sent' : 'cancelled',
            ]);
            $this->event($reminder, 'cancelled', ['reason' => $reason, 'before' => $before, 'after' => 'cancelled'], $actorId);
        }

        return $reminders->count();
    }

    /** @param array<int, string> $keepDedupeKeys */
    private function cancelStalePending(int $appointmentId, array $keepDedupeKeys): void
    {
        $query = Reminder::query()
            ->where('appointment_id', $appointmentId)
            ->whereIn('status', ['pending', 'snoozed', 'transferred'])
            ->where('notification_status', '!=', 'sent');
        if ($keepDedupeKeys !== []) {
            $query->whereNotIn('dedupe_key', $keepDedupeKeys);
        }
        $query->lockForUpdate()->get()->each(function (Reminder $reminder): void {
            $before = $reminder->due_at->toIso8601String();
            $reminder->update([
                'status' => 'cancelled',
                'notification_status' => $reminder->notification_status === 'sent' ? 'sent' : 'cancelled',
            ]);
            $this->event($reminder, 'cancelled', ['reason' => 'appointment_rescheduled', 'before_due_at' => $before]);
        });
    }

    private function syncReminder(
        int $appointmentId,
        int $customerId,
        ?int $assignedTo,
        CarbonImmutable $scheduledAt,
        CarbonImmutable $dueAt,
        string $dedupeKey,
        string $contentKey,
        bool $includeExpectedArrival,
    ): int {
        $localizedContent = [
            'title' => ['key' => $contentKey.'.title', 'parameters' => []],
            'suggestion' => ['key' => $contentKey.'.suggestion', 'parameters' => []],
            'notes' => $includeExpectedArrival
                ? [['key' => 'reminders.system_reminders.arrival_previous_day.expected_arrival', 'parameters' => ['scheduled_at' => $scheduledAt->format('Y-m-d H:i')]]]
                : [],
        ];
        $reminder = Reminder::query()->where('dedupe_key', $dedupeKey)->first();
        $attributes = [
            'customer_id' => $customerId,
            'appointment_id' => $appointmentId,
            'assigned_to' => $assignedTo,
            'source_type' => 'system',
            'reminder_type' => 'appointment',
            'title' => (string) __($contentKey.'.title'),
            'suggestion' => (string) __($contentKey.'.suggestion'),
            'notes' => $includeExpectedArrival
                ? (string) __('reminders.system_reminders.arrival_previous_day.expected_arrival', ['scheduled_at' => $scheduledAt->format('Y-m-d H:i')])
                : null,
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
