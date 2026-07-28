<?php

namespace App\Modules\Settlement\Application\Services;

use App\Modules\Settlement\Infrastructure\Models\SettlementRun;
use App\Modules\Settlement\Jobs\SendSettlementNotification;
use DomainException;

final class SettlementNotificationDispatcher
{
    public function dispatchCompleted(): int
    {
        $statuses = ['pending'];
        if ((bool) config('dingtalk.enabled')) {
            $statuses[] = 'disabled';
        }
        $ids = SettlementRun::query()
            ->where('status', 'completed')
            ->whereIn('notification_status', $statuses)
            ->pluck('id');
        foreach ($ids as $id) {
            SettlementRun::query()->whereKey($id)->update(['notification_status' => 'queued']);
            SendSettlementNotification::dispatch((string) $id);
        }

        return $ids->count();
    }

    public function retry(string $runId): void
    {
        $run = SettlementRun::query()->findOrFail($runId);
        if ($run->status !== 'completed') {
            throw new DomainException('仅已完成月结批次可重试通知。');
        }
        if (! in_array($run->notification_status, ['failed', 'disabled'], true)) {
            throw new DomainException('当前通知状态无需重试。');
        }
        $run->update(['notification_status' => 'queued', 'notification_error' => null]);
        SendSettlementNotification::dispatch($run->id)->afterCommit();
    }
}
