<?php

namespace App\Modules\Reminder\Application\Services;

use App\Infrastructure\Localization\SupportedLocale;
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
        $reminders = Reminder::query()
            ->whereIn('status', ['pending', 'snoozed', 'transferred'])
            ->whereIn('notification_status', $statuses)
            ->where('due_at', '<=', now())
            ->get(['id', 'assigned_to', 'created_by']);
        foreach ($reminders as $reminder) {
            Reminder::query()->whereKey($reminder->id)->update(['notification_status' => 'queued']);
            SendReminderNotification::dispatch((int) $reminder->id, $this->localeFor($reminder));
        }

        return $reminders->count();
    }

    public function retry(int $reminderId, User $actor): void
    {
        $reminder = Reminder::query()->findOrFail($reminderId);
        if (! $actor->is_super_admin
            && $reminder->assigned_to !== $actor->id
            && $reminder->created_by !== $actor->id) {
            throw new DomainException(__('reminders.errors.retry_forbidden'));
        }
        if (! in_array($reminder->notification_status, ['failed', 'disabled'], true)) {
            throw new DomainException(__('reminders.errors.retry_not_needed'));
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
        SendReminderNotification::dispatch($reminder->id, $this->localeFor($reminder))->afterCommit();
    }

    private function localeFor(Reminder $reminder): string
    {
        $user = $reminder->assigned_to === null
            ? null
            : User::query()->find($reminder->assigned_to);
        $user ??= $reminder->created_by === null
            ? null
            : User::query()->find($reminder->created_by);

        return (SupportedLocale::fromCandidate($user?->preferred_locale) ?? SupportedLocale::default())->value;
    }
}
