<?php

namespace App\Modules\Reminder\Application\Contracts;

use Carbon\CarbonImmutable;

interface ReportReminderReader
{
    /**
     * @return array{
     *   overdue_customers: int,
     *   followup_completion_rate: float,
     * }
     */
    public function dashboard(CarbonImmutable $from, CarbonImmutable $to): array;

    /**
     * @return array{
     *   pending_reminders: int,
     *   today_tasks: array<int, array{
     *     customer_id: int,
     *     time: string,
     *     title: string,
     *     title_key: string|null,
     *     title_parameters: array<string, scalar>,
     *     tag: string,
     *     priority: int
     *   }>
     * }
     */
    public function overview(CarbonImmutable $now): array;

    /**
     * @param  list<int>  $ownerIds
     * @return array{pending: int, overdue: int, owners: array<int, array{pending: int, overdue: int}>}
     */
    public function teamOverview(array $ownerIds, CarbonImmutable $now): array;
}
