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

        $this->queueDingTalk(
            $eventType,
            $eventKey,
            $title,
            $body,
            $link,
            $configs->where('channel', 'dingtalk')->pluck('user_id')->unique()->values()->all(),
        );
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

    /** @param list<int> $userIds */
    public function notifyUsers(string $eventType, string $eventKey, string $title, string $body, array $userIds, ?string $link = null): void
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn (int $id): bool => $id > 0)));
        $this->notifyInternalUsers($eventType, $eventKey, $title, $body, $userIds, $link);
        $this->queueDingTalk($eventType, $eventKey, $title, $body, $link, $userIds);
    }

    public function unreadForUser(int $userId, int $limit = 8): array
    {
        return InternalNotification::query()->where('user_id', $userId)->whereNull('read_at')
            ->orderByDesc('created_at')->limit(max(1, min($limit, 20)))->get()
            ->map(fn (InternalNotification $notification): array => [
                'id' => (int) $notification->id,
                'title' => (string) $notification->title,
                'body' => (string) $notification->body,
                'link' => $notification->link === null ? null : (string) $notification->link,
                'created_at' => $notification->created_at?->toIso8601String(),
            ])->all();
    }

    public function unreadCountForUser(int $userId): int
    {
        return InternalNotification::query()->where('user_id', $userId)->whereNull('read_at')->count();
    }

    public function markNotificationRead(int $notificationId, int $userId): ?string
    {
        $notification = InternalNotification::query()->where('user_id', $userId)
            ->whereKey($notificationId)->first();
        if ($notification === null) {
            return null;
        }
        $notification->update(['read_at' => $notification->read_at ?? now()]);

        return $notification->link === null ? null : (string) $notification->link;
    }

    public function markReminderNotificationReadForUser(int $reminderId, int $userId): void
    {
        InternalNotification::query()->where('user_id', $userId)->where('event_type', 'reminder')
            ->where('event_key', 'reminder:'.$reminderId)->whereNull('read_at')->update(['read_at' => now()]);
    }

    /** @param list<int> $userIds */
    private function queueDingTalk(string $eventType, string $eventKey, string $title, string $body, ?string $link, array $userIds): void
    {
        if ($userIds === [] || ! $this->sender->enabled()) {
            return;
        }

        $recipients = User::query()
            ->whereIn('id', $userIds)
            ->whereIn('dingtalk_mention_type', ['user_id', 'mobile'])
            ->whereNotNull('dingtalk_mention_value')
            ->where('dingtalk_mention_value', '<>', '')
            ->get(['dingtalk_mention_type', 'dingtalk_mention_value'])
            ->map(static fn (User $user): array => [
                'type' => (string) $user->dingtalk_mention_type,
                'value' => (string) $user->dingtalk_mention_value,
            ])
            ->unique(fn (array $recipient): string => $recipient['type'].':'.$recipient['value'])
            ->values()
            ->all();
        if ($recipients === []) {
            return;
        }

        $delivery = NotificationDelivery::query()->firstOrCreate(
            ['event_type' => $eventType, 'event_key' => $eventKey, 'channel' => 'dingtalk'],
            [
                'title' => $title,
                'body' => $body,
                'link' => $link,
                'recipients' => $recipients,
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
                'recipients' => $recipients,
                'status' => 'queued',
                'last_error' => null,
            ]);
            SendDingTalkNotification::dispatch($delivery->id)->afterCommit();
        }
    }
}
