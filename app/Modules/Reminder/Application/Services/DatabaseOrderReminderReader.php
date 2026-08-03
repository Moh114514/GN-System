<?php

namespace App\Modules\Reminder\Application\Services;

use App\Modules\Reminder\Application\Contracts\OrderReminderReader;
use App\Modules\Reminder\Infrastructure\Models\Reminder;

final class DatabaseOrderReminderReader implements OrderReminderReader
{
    public function forOrder(int $orderId): array
    {
        return Reminder::query()
            ->where('order_id', $orderId)
            ->orderBy('due_at')
            ->get(['id', 'title', 'due_at', 'status', 'assigned_to', 'notes'])
            ->map(fn (Reminder $reminder): array => [
                'id' => (int) $reminder->id,
                'title' => (string) $reminder->title,
                'due_at' => $reminder->due_at->format('Y-m-d H:i'),
                'status' => (string) $reminder->status,
                'assigned_to' => $reminder->assigned_to === null ? null : (int) $reminder->assigned_to,
                'notes' => $reminder->notes,
            ])->all();
    }
}
