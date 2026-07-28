<?php

namespace App\Modules\Reminder\Jobs;

use App\Modules\Reminder\Application\Services\ReminderNotifier;
use App\Modules\Reminder\Infrastructure\Models\Reminder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SendReminderNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60, 300];

    public function __construct(public int $reminderId) {}

    public function handle(ReminderNotifier $notifier): void
    {
        $notifier->send($this->reminderId);
    }

    public function failed(Throwable $exception): void
    {
        $reminder = Reminder::query()->find($this->reminderId);
        if ($reminder === null) {
            return;
        }
        $reminder->update(['notification_status' => 'failed']);
        app(ReminderNotifier::class)->event($reminder, 'notification_failed', ['error' => $exception->getMessage()]);
    }
}
