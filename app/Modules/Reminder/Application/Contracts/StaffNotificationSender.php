<?php

namespace App\Modules\Reminder\Application\Contracts;

interface StaffNotificationSender
{
    public function enabled(): bool;

    /** @param list<string> $recipients */
    public function send(string $title, string $text, ?string $link = null, array $recipients = []): void;
}
