<?php

namespace App\Modules\Reminder\Application\Services;

use App\Modules\Reminder\Application\Contracts\ReportReminderReader;
use App\Modules\Reminder\Infrastructure\Models\FollowupRecord;
use App\Modules\Reminder\Infrastructure\Models\Reminder;
use Carbon\CarbonImmutable;

final class DatabaseReportReminderReader implements ReportReminderReader
{
    public function dashboard(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $due = Reminder::query()->whereBetween('due_at', [$from, $to]);
        $dueCount = (clone $due)->count();
        $completedCount = (clone $due)->where('status', 'completed')->count();
        $today = CarbonImmutable::now('Asia/Shanghai');
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
                ->get(['customer_id', 'due_at', 'title', 'reminder_type', 'priority'])
                ->map(fn (Reminder $reminder): array => [
                    'customer_id' => (int) $reminder->customer_id,
                    'time' => $reminder->due_at->setTimezone('Asia/Shanghai')->format('H:i'),
                    'title' => (string) $reminder->title,
                    'tag' => $this->reminderType((string) $reminder->reminder_type),
                    'priority' => (int) $reminder->priority,
                ])
                ->all(),
        ];
    }

    private function reminderType(string $type): string
    {
        return [
            'pre_visit' => '到院提醒',
            'post_treatment' => '术后回访',
            'birthday' => '生日提醒',
            'repurchase' => '复购窗口',
            'manual' => '人工提醒',
        ][$type] ?? '待办提醒';
    }
}
