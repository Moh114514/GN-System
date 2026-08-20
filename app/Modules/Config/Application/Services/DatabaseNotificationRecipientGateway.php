<?php

namespace App\Modules\Config\Application\Services;

use App\Models\User;
use App\Modules\Config\Application\Contracts\NotificationRecipientGateway;
use App\Modules\Config\Application\Jobs\SendDingTalkNotification;
use App\Modules\Config\Infrastructure\Models\InternalNotification;
use App\Modules\Config\Infrastructure\Models\NotificationDelivery;
use App\Modules\Config\Infrastructure\Models\NotificationRecipientConfig;
use App\Modules\Reminder\Application\Contracts\StaffNotificationSender;

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

        $dingtalkRecipients = User::query()
            ->whereIn('id', $configs->where('channel', 'dingtalk')->pluck('user_id')->unique()->all())
            ->whereIn('dingtalk_mention_type', ['user_id', 'mobile'])
            ->whereNotNull('dingtalk_mention_type')
            ->whereNotNull('dingtalk_mention_value')
            ->where('dingtalk_mention_value', '<>', '')
            ->get(['dingtalk_mention_type', 'dingtalk_mention_value'])
            ->map(static fn (User $user): array => [
                'type' => (string) $user->dingtalk_mention_type,
                'value' => (string) $user->dingtalk_mention_value,
            ])
            ->values()
            ->all();
        if ($dingtalkRecipients === [] || ! $this->sender->enabled()) {
            return;
        }

        $delivery = NotificationDelivery::query()->firstOrCreate(
            [
                'event_type' => $eventType,
                'event_key' => $eventKey,
                'channel' => 'dingtalk',
            ],
            [
                'title' => $title,
                'body' => $body,
                'link' => $link,
                'recipients' => $dingtalkRecipients,
                'status' => 'queued',
            ],
        );
        if ($delivery->wasRecentlyCreated) {
            SendDingTalkNotification::dispatch($delivery->id)->afterCommit();

            return;
        }
        if ($delivery->status === 'failed') {
            $delivery->update([
                'title' => $title,
                'body' => $body,
                'link' => $link,
                'recipients' => $dingtalkRecipients,
                'status' => 'queued',
                'last_error' => null,
            ]);
            SendDingTalkNotification::dispatch($delivery->id)->afterCommit();
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
