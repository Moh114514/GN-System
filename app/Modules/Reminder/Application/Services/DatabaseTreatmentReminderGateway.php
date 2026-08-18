<?php

namespace App\Modules\Reminder\Application\Services;

use App\Infrastructure\Time\BusinessClock;
use App\Modules\Reminder\Application\Contracts\TreatmentReminderGateway;
use App\Modules\Reminder\Application\Data\CompletedTreatmentData;
use App\Modules\Reminder\Application\Data\CustomerTreatmentCompletedData;
use App\Modules\Reminder\Infrastructure\Models\Reminder;
use App\Modules\Reminder\Infrastructure\Models\ReminderEvent;
use Carbon\CarbonImmutable;

final class DatabaseTreatmentReminderGateway implements TreatmentReminderGateway
{
    private const SCHEDULE = [7, 30];

    public function __construct(private readonly BusinessClock $clock) {}

    public function schedule(CompletedTreatmentData $data): void
    {
        $this->scheduleAt(
            customerId: $data->customerId,
            orderId: $data->orderId,
            projectName: $data->projectName,
            completedAt: $data->completedOn,
            ownerId: $data->ownerId,
            actorId: $data->actorId,
            source: 'order_completed',
        );
    }

    public function scheduleForCustomer(CustomerTreatmentCompletedData $data): void
    {
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
    ): void {
        foreach (self::SCHEDULE as $days) {
            $dueAt = $completedAt->addDays($days)->setTime(9, 0);
            if ($dueAt->isBefore($this->clock->now()->startOfDay())) {
                continue;
            }

            $type = $days === 7 ? 'postop_7d' : 'postop_30d';
            $dedupeKey = hash('sha256', "customer:{$customerId}:treatment:{$completedAt->toIso8601String()}:{$type}");
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
}
