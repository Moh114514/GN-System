<?php

namespace App\Modules\Config\Application\Services;

use App\Models\User;
use App\Modules\Config\Application\Contracts\NotificationRecipientGateway;
use App\Modules\Config\Infrastructure\Models\InternalNotification;
use App\Modules\Config\Infrastructure\Models\NotificationRecipientConfig;
use App\Modules\Reminder\Application\Contracts\StaffNotificationSender;
use Illuminate\Support\Facades\Log;
use Throwable;

final readonly class DatabaseNotificationRecipientGateway implements NotificationRecipientGateway
{
    public function __construct(private StaffNotificationSender $sender) {}

    public function notify(string $eventType, string $eventKey, string $title, string $body, ?string $link = null): void
    {
        $configs = NotificationRecipientConfig::query()
            ->where('event_type', $eventType)
            ->where('enabled', true)
            ->get();
        $userIds = $configs->where('channel', 'internal')->pluck('user_id')->unique()->values()->all();
        $this->notifyInternalUsers($eventType, $eventKey, $title, $body, $userIds, $link);

        $dingtalkIds = User::query()
            ->whereIn('id', $configs->where('channel', 'dingtalk')->pluck('user_id')->unique()->all())
            ->whereNotNull('dingtalk_user_id')
            ->pluck('dingtalk_user_id')
            ->filter()
            ->values()
            ->all();
        if ($dingtalkIds === [] || ! $this->sender->enabled()) {
            return;
        }

        try {
            $this->sender->send($title, $body, $link, $dingtalkIds);
        } catch (Throwable $exception) {
            Log::warning('Configured DingTalk notification failed.', [
                'event_type' => $eventType,
                'event_key' => $eventKey,
                'exception' => $exception,
            ]);
        }
    }

    /** @param list<int> $userIds */
    public function notifyInternalUsers(string $eventType, string $eventKey, string $title, string $body, array $userIds, ?string $link = null): void
    {
        foreach (array_unique(array_map('intval', $userIds)) as $userId) {
            InternalNotification::query()->firstOrCreate(
                ['user_id' => $userId, 'event_key' => $eventKey],
                ['event_type' => $eventType, 'title' => $title, 'body' => $body, 'link' => $link],
            );
        }
    }
}
