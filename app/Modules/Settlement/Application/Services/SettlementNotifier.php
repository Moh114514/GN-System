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
            $run->update(['notification_status' => 'disabled', 'notification_error' => '钉钉未启用']);

            return;
        }
        $this->sender->send(
            '月结生成完成',
            "周期：{$run->period_start->format('Y-m-d')} 至 {$run->period_end->format('Y-m-d')}\n\n代理商：{$run->processed_agents} 家\n\n推广费合计：₩ ".number_format($run->total_commission_krw),
            route('settlements.index'),
        );
        $run->update(['notification_status' => 'sent', 'notification_error' => null, 'notified_at' => now()]);
    }
}
