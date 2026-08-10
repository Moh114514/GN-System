<?php

namespace App\Modules\Settlement\Application\Services;

use App\Infrastructure\Localization\SupportedLocale;
use App\Models\User;
use App\Modules\Settlement\Infrastructure\Models\SettlementRun;
use App\Modules\Settlement\Jobs\SendSettlementNotification;
use Illuminate\Support\Facades\Cache;

final readonly class SettlementRunSummaryUpdater
{
    public function update(SettlementRun $run): SettlementRun
    {
        $members = $run->members()->with('settlement')->get();
        $generated = $members->where('outcome', 'generated');
        $existing = $members->where('outcome', 'existing');
        $failed = $members->where('outcome', 'failed');
        $pending = $members->where('outcome', 'pending');
        $settlements = $members->pluck('settlement')->filter();

        $run->total_agents = $members->count();
        $run->processed_agents = $generated->count();
        $run->existing_agents = $existing->count();
        $run->failed_agents = $failed->count();
        $run->existing_agent_ids = $existing->pluck('agent_id')->map(static fn ($id): int => (int) $id)->sort()->values()->all();
        $run->errors = $failed->mapWithKeys(static fn ($member): array => [
            (string) $member->agent_id => [
                'message_key' => $member->error_message_key,
                'parameters' => $member->error_parameters ?? [],
            ],
        ])->all() ?: null;
        $run->total_consumption_krw = (int) $settlements->sum('total_consumption_krw');
        $run->total_commission_krw = (int) $settlements->sum('total_commission_krw');

        if ($pending->isNotEmpty()) {
            $run->status = 'running';
            $run->completed_at = null;
        } elseif ($failed->isNotEmpty()) {
            $run->status = 'partial_failed';
            $run->completed_at = now();
        } else {
            $run->status = 'completed';
            $run->completed_at ??= now();
        }
        $run->save();

        if ($run->progress_key !== null) {
            Cache::put($run->progress_key, [
                'total' => $run->total_agents,
                'processed' => $run->processed_agents,
                'existing' => $run->existing_agents,
                'failed' => $run->failed_agents,
            ], now()->addDays(7));
        }

        if ($run->status === 'completed' && $run->notification_status === 'pending') {
            $run->update(['notification_status' => 'queued']);
            $user = $run->initiated_by === null ? null : User::query()->find($run->initiated_by);
            $locale = (SupportedLocale::fromCandidate($user?->preferred_locale) ?? SupportedLocale::default())->value;
            SendSettlementNotification::dispatch($run->id, $locale)->afterCommit();
        }

        return $run->refresh();
    }
}
