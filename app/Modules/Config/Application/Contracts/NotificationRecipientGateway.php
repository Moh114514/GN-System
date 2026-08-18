<?php

namespace App\Modules\Config\Application\Contracts;

interface NotificationRecipientGateway
{
    public function notify(string $eventType, string $eventKey, string $title, string $body, ?string $link = null): void;

    /** @param list<int> $userIds */
    public function notifyInternalUsers(string $eventType, string $eventKey, string $title, string $body, array $userIds, ?string $link = null): void;
}
