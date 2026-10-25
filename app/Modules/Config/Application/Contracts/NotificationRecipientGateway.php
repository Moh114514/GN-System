<?php

namespace App\Modules\Config\Application\Contracts;

interface NotificationRecipientGateway
{
    public function notify(string $eventType, string $eventKey, string $title, string $body, ?string $link = null): void;

    /** @param list<int> $userIds */
    public function notifyInternalUsers(string $eventType, string $eventKey, string $title, string $body, array $userIds, ?string $link = null): void;

    /** @param list<int> $userIds */
    public function notifyUsers(string $eventType, string $eventKey, string $title, string $body, array $userIds, ?string $link = null): void;

    /** @return list<array{id: int, title: string, body: string, link: string|null, created_at: string|null}> */
    public function unreadForUser(int $userId, int $limit = 8): array;

    public function unreadCountForUser(int $userId): int;

    public function markNotificationRead(int $notificationId, int $userId): ?string;

    public function markReminderNotificationReadForUser(int $reminderId, int $userId): void;
}
