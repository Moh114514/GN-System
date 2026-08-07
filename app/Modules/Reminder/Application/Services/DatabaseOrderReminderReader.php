<?php

namespace App\Modules\Reminder\Application\Services;

use App\Modules\Reminder\Application\Contracts\OrderReminderReader;
use App\Modules\Reminder\Infrastructure\Models\Reminder;

final class DatabaseOrderReminderReader implements OrderReminderReader
{
    public function __construct(private readonly ReminderContentPresenter $contentPresenter) {}

    public function forOrder(int $orderId): array
    {
        return Reminder::query()
            ->where('order_id', $orderId)
            ->orderBy('due_at')
            ->get(['id', 'title', 'due_at', 'status', 'assigned_to', 'notes', 'suggestion', 'localized_content'])
            ->map(function (Reminder $reminder): array {
                $content = $this->contentPresenter->reminder($reminder);

                return [
                    'id' => (int) $reminder->id,
                    'title' => $content['title'],
                    'due_at' => $reminder->due_at->format('Y-m-d H:i'),
                    'status' => (string) $reminder->status,
                    'assigned_to' => $reminder->assigned_to === null ? null : (int) $reminder->assigned_to,
                    'notes' => $content['notes'],
                ];
            })->all();
    }
}
