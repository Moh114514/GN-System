<?php

namespace App\Modules\Reminder\Application\Services;

use App\Models\User;
use App\Modules\Reminder\Infrastructure\Models\Reminder;
use App\Modules\Reminder\Infrastructure\Models\ReminderEvent;
use App\Modules\Reminder\Jobs\SendReminderNotification;
use DomainException;

final class ReminderNotificationDispatcher
{
    public function dispatchDue(): int
    {
        $statuses = ['pending'];
        if ((bool) config('dingtalk.enabled')) {
            $statuses[] = 'disabled';
        }
        $ids = Reminder::query()
            ->whereIn('status', ['pending', 'snoozed', 'transferred'])
            ->whereIn('notification_status', $statuses)
            ->where('due_at', '<=', now())
            ->pluck('id');
        foreach ($ids as $id) {
            Reminder::query()->whereKey($id)->update(['notification_status' => 'queued']);
            SendReminderNotification::dispatch((int) $id);
        }

        return $ids->count();
    }

    public function retry(int $reminderId, User $actor): void
    {
        $reminder = Reminder::query()->findOrFail($reminderId);
        if (! $actor->is_super_admin
            && $reminder->assigned_to !== $actor->id
            && $reminder->created_by !== $actor->id) {
            throw new DomainException('无权重试此提醒的通知。');
        }
        if (! in_array($reminder->notification_status, ['failed', 'disabled'], true)) {
            throw new DomainException('当前通知状态无需重试。');
        }
        $before = $reminder->notification_status;
        $reminder->update(['notification_status' => 'queued']);
        ReminderEvent::query()->create([
            'reminder_id' => $reminder->id,
            'actor_id' => $actor->id,
            'event' => 'notification_retried',
            'properties' => ['before' => $before, 'after' => 'queued'],
            'occurred_at' => now(),
        ]);
        SendReminderNotification::dispatch($reminder->id)->afterCommit();
    }
}
