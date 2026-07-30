<?php

namespace App\Modules\Reminder\Application\Services;

use App\Modules\Reminder\Application\Contracts\ReportReminderReader;
use App\Modules\Reminder\Infrastructure\Models\Reminder;
use Carbon\CarbonImmutable;

final class DatabaseReportReminderReader implements ReportReminderReader
{
    public function dashboard(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $due = Reminder::query()->whereBetween('due_at', [$from, $to]);
        $dueCount = (clone $due)->count();
        $completedCount = (clone $due)->where('status', 'completed')->count();

        return [
            'overdue_customers' => Reminder::query()
                ->whereIn('status', ['pending', 'snoozed'])
                ->where('due_at', '<=', $to)
                ->distinct('customer_id')
                ->count('customer_id'),
            'followup_completion_rate' => $dueCount === 0
                ? 0.0
                : round($completedCount / $dueCount * 100, 2),
        ];
    }
}
