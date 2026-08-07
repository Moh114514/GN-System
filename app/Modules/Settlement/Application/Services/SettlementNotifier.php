<?php

namespace App\Modules\Settlement\Application\Services;

use App\Modules\Reminder\Application\Contracts\StaffNotificationSender;
use App\Modules\Settlement\Infrastructure\Models\SettlementRun;

final readonly class SettlementNotifier
{
    public function __construct(private StaffNotificationSender $sender) {}

    public function send(string $runId): void
    {
        $run = SettlementRun::query()->findOrFail($runId);
        if ($run->notification_status === 'sent') {
            return;
        }
        if (! $this->sender->enabled()) {
            $run->update(['notification_status' => 'disabled', 'notification_error' => 'dingtalk_disabled']);

            return;
        }
        $this->sender->send(
            __('settlements.notifications.title'),
            __('settlements.notifications.body', [
                'from' => $run->period_start->format('Y-m-d'),
                'to' => $run->period_end->format('Y-m-d'),
                'agents' => $run->processed_agents,
                'total' => number_format($run->total_commission_krw),
            ]),
            route('settlements.index'),
        );
        $run->update(['notification_status' => 'sent', 'notification_error' => null, 'notified_at' => now()]);
    }
}
