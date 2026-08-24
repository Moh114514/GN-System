<?php

namespace App\Modules\Reminder\Application\Services;

use App\Infrastructure\Time\BusinessClock;
use App\Modules\Auth\Application\Contracts\AccessContextResolver;
use App\Modules\Customer\Application\Contracts\ReminderCustomerReader;
use App\Modules\Customer\Application\Data\ReminderCustomerData;
use App\Modules\Reminder\Application\Contracts\ReportReminderReader;
use App\Modules\Reminder\Infrastructure\Models\FollowupRecord;
use App\Modules\Reminder\Infrastructure\Models\Reminder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

final class DatabaseReportReminderReader implements ReportReminderReader
{
    public function __construct(private readonly BusinessClock $clock, private readonly AccessContextResolver $access) {}

    public function dashboard(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $due = Reminder::query()->whereBetween('due_at', [$from, $to]);
        $this->applyScope($due);
        $dueCount = (clone $due)->count();
        $completedCount = (clone $due)->where('status', 'completed')->count();
        $today = $this->clock->now();
        $pending = Reminder::query()
            ->whereIn('status', ['pending', 'snoozed'])
            ->where('due_at', '<=', $to);
        $this->applyScope($pending);

        return [
            'overdue_customers' => (clone $pending)
                ->distinct('customer_id')
                ->count('customer_id'),
            'pending_reminders' => (clone $pending)->count(),
            'followup_completion_rate' => $dueCount === 0
                ? 0.0
                : round($completedCount / $dueCount * 100, 2),
            'followup_customers' => $this->scopedFollowups()
                ->where('followed_up_on', '<=', $to->toDateString())
                ->distinct('customer_id')
                ->count('customer_id'),
            'today_tasks' => $this->scopedReminders()
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

    /** @return Builder<Reminder> */
    private function scopedReminders(): Builder
    {
        $query = Reminder::query();
        $context = $this->access->current();
        if ($context->isSuperAdmin()) {
            return $query;
        }
        $query->where(function ($scope) use ($context): void {
            if ($context->userId !== null) {
                $scope->where('assigned_to', $context->userId)->orWhere('created_by', $context->userId);
            }
            if ($context->isBdManager()) {
                $ids = $this->customerIds();
                if ($ids !== []) {
                    $scope->orWhereIn('customer_id', $ids);
                }
            }
        });

        return $query;
    }

    /** @return Builder<FollowupRecord> */
    private function scopedFollowups(): Builder
    {
        $query = FollowupRecord::query();
        $context = $this->access->current();
        if ($context->isSuperAdmin()) {
            return $query;
        }
        $query->where('owner_id', $context->userId);

        return $query;
    }

    /** @return list<int> */
    private function customerIds(): array
    {
        $customers = app(ReminderCustomerReader::class)->candidates();

        return array_map(static fn (ReminderCustomerData $customer): int => $customer->id, $customers);
    }

    /** @param Builder<Reminder> $query */
    private function applyScope(Builder $query): void
    {
        $scope = $this->scopedReminders();
        if ($scope->getQuery()->wheres !== []) {
            $query->whereIn('id', $scope->clone()->select('id'));
        }
    }
}
