<?php

namespace App\Modules\Reminder\Application\Services;

use App\Modules\Reminder\Application\Contracts\TreatmentReminderGateway;
use App\Modules\Reminder\Application\Data\CompletedTreatmentData;
use App\Modules\Reminder\Infrastructure\Models\Reminder;
use App\Modules\Reminder\Infrastructure\Models\ReminderEvent;
use Carbon\CarbonImmutable;

final class DatabaseTreatmentReminderGateway implements TreatmentReminderGateway
{
    private const SCHEDULE = [1, 7, 30, 90, 180];

    public function schedule(CompletedTreatmentData $data): void
    {
        foreach (self::SCHEDULE as $days) {
            $dueAt = $data->completedOn->addDays($days)->setTime(9, 0);
            if ($dueAt->isBefore(CarbonImmutable::now()->startOfDay())) {
                continue;
            }

            $dedupeKey = hash('sha256', "order:{$data->orderId}:post-treatment:{$days}");
            $attributes = [
                'customer_id' => $data->customerId,
                'order_id' => $data->orderId,
                'assigned_to' => $data->ownerId,
                'created_by' => $data->actorId,
                'source_type' => 'system',
                'reminder_type' => 'post_treatment',
                'title' => __('reminders.system_reminders.post_treatment.title', ['days' => $days]),
                'suggestion' => __("reminders.system_reminders.post_treatment.suggestions.{$days}"),
                'notes' => __('reminders.system_reminders.post_treatment.project', ['project' => $data->projectName]),
                'localized_content' => [
                    'title' => ['key' => 'reminders.system_reminders.post_treatment.title', 'parameters' => ['days' => $days]],
                    'suggestion' => ['key' => "reminders.system_reminders.post_treatment.suggestions.{$days}", 'parameters' => []],
                    'notes' => [['key' => 'reminders.system_reminders.post_treatment.project', 'parameters' => ['project' => $data->projectName]]],
                ],
                'priority' => $days <= 7 ? 1 : 2,
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
                    'actor_id' => $data->actorId,
                    'properties' => ['source' => 'order_completed', 'due_at' => $dueAt->toIso8601String()],
                    'occurred_at' => CarbonImmutable::now(),
                ]);
            } elseif ($reminder->status === 'cancelled') {
                $reminder->update($attributes);
                ReminderEvent::query()->create([
                    'reminder_id' => $reminder->id,
                    'event' => 'reactivated',
                    'actor_id' => $data->actorId,
                    'properties' => ['source' => 'order_completed', 'due_at' => $dueAt->toIso8601String()],
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
}
