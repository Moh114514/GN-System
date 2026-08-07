<?php

namespace App\Modules\Settlement\Application\Services;

use App\Infrastructure\Localization\SupportedLocale;
use App\Models\User;
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
        $runs = SettlementRun::query()
            ->where('status', 'completed')
            ->whereIn('notification_status', $statuses)
            ->get(['id', 'initiated_by']);
        foreach ($runs as $run) {
            SettlementRun::query()->whereKey($run->id)->update(['notification_status' => 'queued']);
            SendSettlementNotification::dispatch((string) $run->id, $this->localeFor($run));
        }

        return $runs->count();
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
        SendSettlementNotification::dispatch($run->id, $this->localeFor($run))->afterCommit();
    }

    private function localeFor(SettlementRun $run): string
    {
        $user = $run->initiated_by === null ? null : User::query()->find($run->initiated_by);

        return (SupportedLocale::fromCandidate($user?->preferred_locale) ?? SupportedLocale::default())->value;
    }
}
