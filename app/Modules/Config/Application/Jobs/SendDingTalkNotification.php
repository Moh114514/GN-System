<?php

namespace App\Modules\Config\Application\Jobs;

use App\Modules\Config\Infrastructure\Models\NotificationDelivery;
use App\Modules\Reminder\Application\Contracts\StaffNotificationSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SendDingTalkNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60, 300];

    public function __construct(public int $deliveryId) {}

    public function handle(StaffNotificationSender $sender): void
    {
        $delivery = NotificationDelivery::query()->findOrFail($this->deliveryId);
        if ($delivery->status === 'sent') {
            return;
        }

        $delivery->increment('attempts');
        $delivery->update(['status' => 'sending', 'last_error' => null]);

        try {
            $sender->send(
                (string) $delivery->title,
                (string) $delivery->body,
                $delivery->link === null ? null : (string) $delivery->link,
                array_values(array_map('strval', $delivery->recipients ?? [])),
            );
        } catch (Throwable $exception) {
            $delivery->update([
                'status' => 'failed',
                'last_error' => $exception->getMessage(),
            ]);
            throw $exception;
        }

        $delivery->update([
            'status' => 'sent',
            'last_error' => null,
            'sent_at' => now(),
        ]);
    }

    public function failed(Throwable $exception): void
    {
        NotificationDelivery::query()->whereKey($this->deliveryId)->update([
            'status' => 'failed',
            'last_error' => $exception->getMessage(),
        ]);
    }
}
