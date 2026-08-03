<?php

namespace App\Modules\Reminder\Application\Contracts;

/**
 * Read-only order detail data owned by Reminder.
 */
interface OrderReminderReader
{
    /** @return array<int, array{id: int, title: string, due_at: string, status: string, assigned_to: int|null, notes: string|null}> */
    public function forOrder(int $orderId): array;
}
