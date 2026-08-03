<?php

namespace App\Modules\Reminder\Application\Services;

use App\Modules\Reminder\Application\Contracts\TreatmentReminderGateway;
use App\Modules\Reminder\Application\Data\CompletedTreatmentData;
use App\Modules\Reminder\Infrastructure\Models\Reminder;
use App\Modules\Reminder\Infrastructure\Models\ReminderEvent;
use Carbon\CarbonImmutable;

final class DatabaseTreatmentReminderGateway implements TreatmentReminderGateway
{
    /** @var array<int, string> */
    private const SCHEDULE = [
        1 => '问候恢复情况',
        7 => '跟进恢复进度',
        30 => '确认效果与复购意向',
        90 => '中期效果回访',
        180 => '长期效果与复购回访',
    ];

    public function schedule(CompletedTreatmentData $data): void
    {
        foreach (self::SCHEDULE as $days => $suggestion) {
            $dueAt = $data->completedOn->addDays($days)->setTime(9, 0);
            if ($dueAt->isBefore(CarbonImmutable::now()->startOfDay())) {
                continue;
            }
            $dedupeKey = hash('sha256', "order:{$data->orderId}:post-treatment:{$days}");
            $reminder = Reminder::query()->firstOrCreate(
                ['dedupe_key' => $dedupeKey],
                [
                    'customer_id' => $data->customerId,
                    'order_id' => $data->orderId,
                    'assigned_to' => $data->ownerId,
                    'created_by' => $data->actorId,
                    'source_type' => 'system',
                    'reminder_type' => 'post_treatment',
                    'title' => "术后第 {$days} 天跟进",
                    'suggestion' => $suggestion,
                    'notes' => "关联项目：{$data->projectName}",
                    'priority' => $days <= 7 ? 1 : 2,
                    'due_at' => $dueAt,
                    'status' => 'pending',
                    'notification_status' => 'pending',
                ],
            );
            if ($reminder->wasRecentlyCreated) {
                ReminderEvent::query()->create([
                    'reminder_id' => $reminder->id,
                    'event' => 'generated',
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
                $reminder->update([
                    'status' => 'cancelled',
                    'notification_status' => 'cancelled',
                    'notes' => trim((string) $reminder->notes)."\n状态回退：".trim($reason),
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
