<?php

namespace App\Modules\Reminder\Application\Contracts;

use Carbon\CarbonImmutable;

interface ReportReminderReader
{
    /**
     * @return array{
     *   overdue_customers: int,
     *   pending_reminders: int,
     *   followup_completion_rate: float,
     *   followup_customers: int,
     *   today_tasks: array<int, array{
     *     customer_id: int,
     *     time: string,
     *     title: string,
     *     tag: string,
     *     priority: int
     *   }>
     * }
     */
    public function dashboard(CarbonImmutable $from, CarbonImmutable $to): array;

    /**
     * @param  list<int>  $ownerIds
     * @return array{pending: int, overdue: int, owners: array<int, array{pending: int, overdue: int}>}
     */
    public function teamOverview(array $ownerIds, CarbonImmutable $now): array;
}
