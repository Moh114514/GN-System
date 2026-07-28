<?php

namespace App\Modules\Reminder\Application\Contracts;

interface StaffNotificationSender
{
    public function enabled(): bool;

    public function send(string $title, string $text, ?string $link = null): void;
}
