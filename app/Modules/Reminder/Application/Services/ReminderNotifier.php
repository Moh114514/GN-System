<?php

namespace App\Modules\Reminder\Application\Services;

use App\Models\User;
use App\Modules\Customer\Application\Contracts\ReminderCustomerReader;
use App\Modules\Reminder\Application\Contracts\StaffNotificationSender;
use App\Modules\Reminder\Infrastructure\Models\Reminder;
use App\Modules\Reminder\Infrastructure\Models\ReminderEvent;

final readonly class ReminderNotifier
{
    public function __construct(
        private StaffNotificationSender $sender,
        private ReminderCustomerReader $customers,
    ) {}

    public function send(int $reminderId): void
    {
        $reminder = Reminder::query()->findOrFail($reminderId);
        if ($reminder->notification_status === 'sent' || ! in_array($reminder->status, ['pending', 'snoozed', 'transferred'], true)) {
            return;
        }
        if (! $this->sender->enabled()) {
            $reminder->update(['notification_status' => 'disabled']);
            $this->event($reminder, 'notification_disabled', ['reason' => 'dingtalk_disabled']);

            return;
        }
        $customer = $this->customers->byId((int) $reminder->customer_id);
        $owner = $reminder->assigned_to === null ? null : User::query()->find($reminder->assigned_to);
        $ownerName = $owner === null ? __('reminders.notifications.unassigned') : $owner->name;
        $this->sender->send(
            (string) $reminder->title,
            __('reminders.notifications.body', [
                'customer' => $customer->name,
                'owner' => $ownerName,
                'due_at' => $reminder->due_at->format('Y-m-d H:i'),
                'suggestion' => $reminder->suggestion ?: __('reminders.notifications.no_script'),
            ]),
            route('reminders.index'),
        );
        $reminder->update(['notification_status' => 'sent', 'notified_at' => now()]);
        $this->event($reminder, 'notified', []);
    }

    /** @param array<string, mixed> $properties */
    public function event(Reminder $reminder, string $event, array $properties): void
    {
        ReminderEvent::query()->create([
            'reminder_id' => $reminder->id,
            'event' => $event,
            'properties' => $properties,
            'occurred_at' => now(),
        ]);
    }
}
