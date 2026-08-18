<?php

namespace App\Modules\Reminder\Application\Services;

use App\Infrastructure\Time\BusinessClock;
use App\Modules\Reminder\Application\Contracts\ReportReminderReader;
use App\Modules\Reminder\Infrastructure\Models\FollowupRecord;
use App\Modules\Reminder\Infrastructure\Models\Reminder;
use Carbon\CarbonImmutable;

final class DatabaseReportReminderReader implements ReportReminderReader
{
    public function __construct(private readonly BusinessClock $clock) {}

    public function dashboard(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $due = Reminder::query()->whereBetween('due_at', [$from, $to]);
        $dueCount = (clone $due)->count();
        $completedCount = (clone $due)->where('status', 'completed')->count();
        $today = $this->clock->now();
        $pending = Reminder::query()
            ->whereIn('status', ['pending', 'snoozed'])
            ->where('due_at', '<=', $to);

        return [
            'overdue_customers' => (clone $pending)
                ->distinct('customer_id')
                ->count('customer_id'),
            'pending_reminders' => (clone $pending)->count(),
            'followup_completion_rate' => $dueCount === 0
                ? 0.0
                : round($completedCount / $dueCount * 100, 2),
            'followup_customers' => FollowupRecord::query()
                ->where('followed_up_on', '<=', $to->toDateString())
                ->distinct('customer_id')
                ->count('customer_id'),
            'today_tasks' => Reminder::query()
                ->whereIn('status', ['pending', 'snoozed'])
                ->whereBetween('due_at', [$today->startOfDay(), $today->endOfDay()])
                ->orderBy('due_at')
                ->orderByDesc('priority')
                ->limit(5)
                ->get(['customer_id', 'due_at', 'title', 'reminder_type', 'priority', 'localized_content'])
                ->map(function (Reminder $reminder): array {
                    $title = is_array($reminder->localized_content)
                        ? ($reminder->localized_content['title'] ?? null)
                        : null;

                    return [
                        'customer_id' => (int) $reminder->customer_id,
                        'time' => $reminder->due_at->setTimezone('Asia/Shanghai')->format('H:i'),
                        'title' => (string) $reminder->title,
                        'title_key' => is_array($title) && is_string($title['key'] ?? null) ? $title['key'] : null,
                        'title_parameters' => is_array($title) && is_array($title['parameters'] ?? null) ? $title['parameters'] : [],
                        'tag' => (string) $reminder->reminder_type,
                        'priority' => (int) $reminder->priority,
                    ];
                })
                ->all(),
        ];
    }
}
