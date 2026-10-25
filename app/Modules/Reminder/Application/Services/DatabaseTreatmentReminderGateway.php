<?php

namespace App\Modules\Reminder\Application\Services;

use App\Infrastructure\Time\BusinessClock;
use App\Modules\Config\Application\Contracts\NotificationRecipientGateway;
use App\Modules\Reminder\Application\Contracts\TreatmentReminderGateway;
use App\Modules\Reminder\Application\Data\CompletedTreatmentData;
use App\Modules\Reminder\Application\Data\CustomerTreatmentCompletedData;
use App\Modules\Reminder\Infrastructure\Models\Reminder;
use App\Modules\Reminder\Infrastructure\Models\ReminderEvent;
use App\Modules\Reminder\Infrastructure\Models\ReminderRule;
use Carbon\CarbonImmutable;

final class DatabaseTreatmentReminderGateway implements TreatmentReminderGateway
{
    private const SCHEDULE = [7, 30];

    public function __construct(private readonly BusinessClock $clock, private readonly NotificationRecipientGateway $notifications) {}

    public function schedule(CompletedTreatmentData $data): void
    {
        if ($data->sourceType !== 'direct') {
            return;
        }

        $this->scheduleAt(
            customerId: $data->customerId,
            orderId: $data->orderId,
            projectName: $data->projectName,
            completedAt: $data->completedOn,
            ownerId: $data->ownerId,
            actorId: $data->actorId,
            source: 'order_completed',
            visitId: $data->visitId,
        );
    }

    public function scheduleForCustomer(CustomerTreatmentCompletedData $data): void
    {
        if ($data->sourceType !== 'direct') {
            return;
        }

        $this->scheduleAt(
            customerId: $data->customerId,
            orderId: null,
            projectName: $data->projectName,
            completedAt: $data->completedAt,
            ownerId: $data->ownerId,
            actorId: $data->actorId,
            source: 'customer_status_changed',
        );
    }

    private function scheduleAt(
        int $customerId,
        ?int $orderId,
        string $projectName,
        CarbonImmutable $completedAt,
        ?int $ownerId,
        ?int $actorId,
        string $source,
        ?int $visitId = null,
    ): void {
        foreach (self::SCHEDULE as $days) {
            $dueAt = $completedAt->addDays($days)->setTime(9, 0);
            if ($dueAt->isBefore($this->clock->now()->startOfDay())) {
                continue;
            }

            $type = $days === 7 ? 'postop_7d' : 'postop_30d';
            $visitIdentity = $visitId === null ? $completedAt->toIso8601String() : "visit:{$visitId}";
            $dedupeKey = hash('sha256', "customer:{$customerId}:treatment:{$visitIdentity}:{$type}");
            $attributes = [
                'customer_id' => $customerId,
                'order_id' => $orderId,
                'assigned_to' => $ownerId,
                'created_by' => $actorId,
                'source_type' => 'system',
                'reminder_type' => 'post_treatment',
                'title' => __('reminders.system_reminders.post_treatment.title', ['days' => $days]),
                'suggestion' => __("reminders.system_reminders.post_treatment.suggestions.{$days}"),
                'notes' => __('reminders.system_reminders.post_treatment.project', ['project' => $projectName]),
                'localized_content' => [
                    'title' => ['key' => 'reminders.system_reminders.post_treatment.title', 'parameters' => ['days' => $days]],
                    'suggestion' => ['key' => "reminders.system_reminders.post_treatment.suggestions.{$days}", 'parameters' => []],
                    'notes' => [['key' => 'reminders.system_reminders.post_treatment.project', 'parameters' => ['project' => $projectName]]],
                ],
                'priority' => $days === 7 ? 1 : 2,
                'due_at' => $dueAt,
                'status' => 'pending',
                'notification_status' => 'pending',
                'completed_at' => null,
                'completed_by' => null,
            ];
            $reminder = Reminder::query()->where('dedupe_key', $dedupeKey)->first();
            if ($reminder === null) {
                $reminder = Reminder::query()->create(['dedupe_key' => $dedupeKey, ...$attributes]);
                ReminderEvent::query()->create([
                    'reminder_id' => $reminder->id,
                    'event' => 'generated',
                    'actor_id' => $actorId,
                    'properties' => ['source' => $source, 'due_at' => $dueAt->toIso8601String()],
                    'occurred_at' => CarbonImmutable::now(),
                ]);
            } elseif ($reminder->status === 'cancelled') {
                $reminder->update($attributes);
                ReminderEvent::query()->create([
                    'reminder_id' => $reminder->id,
                    'event' => 'reactivated',
                    'actor_id' => $actorId,
                    'properties' => ['source' => $source, 'due_at' => $dueAt->toIso8601String()],
                    'occurred_at' => CarbonImmutable::now(),
                ]);
            }
        }
    }

    public function cancelForOrder(int $orderId, int $actorId, string $reason): void
    {
        Reminder::query()
            ->where('order_id', $orderId)
            ->where('source_type', 'system')
            ->where('reminder_type', 'post_treatment')
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->get()
            ->each(function (Reminder $reminder) use ($actorId, $reason): void {
                $rollback = ['key' => 'reminders.system_reminders.rollback_note', 'parameters' => ['reason' => trim($reason)]];
                $localizedContent = is_array($reminder->localized_content) ? $reminder->localized_content : [];
                $notes = $localizedContent['notes'] ?? [];
                $notes = array_is_list($notes) ? $notes : [$notes];
                if ($notes === [] && trim((string) $reminder->notes) !== '') {
                    $notes[] = trim((string) $reminder->notes);
                }
                $notes[] = $rollback;
                $reminder->update([
                    'status' => 'cancelled',
                    'notification_status' => 'cancelled',
                    'notes' => trim((string) $reminder->notes)."\n".__('reminders.system_reminders.rollback_note', ['reason' => trim($reason)]),
                    'localized_content' => [...$localizedContent, 'notes' => $notes],
                ]);
                ReminderEvent::query()->create([
                    'reminder_id' => $reminder->id,
                    'event' => 'cancelled',
                    'actor_id' => $actorId,
                    'properties' => ['reason' => trim($reason), 'source' => 'order_status_rollback'],
                    'occurred_at' => CarbonImmutable::now(),
                ]);
            });
    }

    public function cancelPendingBirthdayReminders(int $customerId, int $actorId): int
    {
        $reminders = Reminder::query()
            ->where('customer_id', $customerId)
            ->where('source_type', 'rule')
            ->where('reminder_type', 'date_offset')
            ->whereIn('rule_id', ReminderRule::query()->where('trigger_type', 'date_offset')->where('trigger_config->field', 'birth_date')->select('id'))
            ->whereIn('status', ['pending', 'snoozed', 'transferred'])
            ->lockForUpdate()
            ->get();
        foreach ($reminders as $reminder) {
            $reminder->update([
                'status' => 'cancelled',
                'notification_status' => $reminder->notification_status === 'sent' ? 'sent' : 'cancelled',
            ]);
            ReminderEvent::query()->create([
                'reminder_id' => $reminder->id,
                'event' => 'cancelled',
                'actor_id' => $actorId,
                'properties' => ['source' => 'customer_birth_date_changed'],
                'occurred_at' => CarbonImmutable::now(),
            ]);
        }

        return $reminders->count();
    }

    public function transferForCustomer(int $customerId, int $ownerId, int $actorId): int
    {
        $reminders = Reminder::query()
            ->where('customer_id', $customerId)
            ->whereIn('status', ['pending', 'snoozed', 'transferred'])
            ->lockForUpdate()
            ->get();
        foreach ($reminders as $reminder) {
            $before = $reminder->assigned_to;
            if ($before !== null) {
                $this->notifications->markReminderNotificationReadForUser((int) $reminder->id, (int) $before);
            }
            $reminder->update([
                'assigned_to' => $ownerId,
                'status' => 'transferred',
                'notification_status' => 'pending',
            ]);
            ReminderEvent::query()->create([
                'reminder_id' => $reminder->id,
                'event' => 'owner_transferred',
                'actor_id' => $actorId,
                'properties' => ['before' => $before, 'after' => $ownerId],
                'occurred_at' => CarbonImmutable::now(),
            ]);
        }

        return $reminders->count();
    }
}
