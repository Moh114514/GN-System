<?php

namespace App\Modules\Reminder\Application\Contracts;

use Carbon\CarbonImmutable;

interface ReportReminderReader
{
    /** @return array{overdue_customers: int, followup_completion_rate: float} */
    public function dashboard(CarbonImmutable $from, CarbonImmutable $to): array;
}
