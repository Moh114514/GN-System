<?php

namespace App\Modules\Settlement\Application\Services;

use App\Modules\Settlement\Infrastructure\Models\SettlementRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

/**
 * Compares the business projection with Laravel's persisted batch state.
 * Queue health is intentionally based on real time, not the Test Clock.
 */
final readonly class SettlementRunReconciler
{
    private const STALE_AFTER_MINUTES = 30;

    public function __construct(private SettlementRunSummaryUpdater $summary) {}

    /** @return array{checked: int, stalled: int, repaired: int} */
    public function reconcile(): array
    {
        $result = ['checked' => 0, 'stalled' => 0, 'repaired' => 0];

        SettlementRun::query()
            ->whereIn('status', ['queued', 'running', 'stalled'])
            ->orderBy('started_at')
            ->each(function (SettlementRun $run) use (&$result): void {
                $result['checked']++;
                $pending = $run->members()->where('outcome', 'pending')->count();
                if ($pending === 0) {
                    $this->summary->update($run);

                    return;
                }

                $reason = $this->anomalyReason($run);
                if ($reason === null) {
                    if ($run->status === 'stalled') {
                        $this->summary->update($run);
                        $result['repaired']++;
                    }

                    return;
                }

                $run->update([
                    'status' => 'stalled',
                    'completed_at' => null,
                ]);
                Log::warning('Settlement run queue state is inconsistent.', [
                    'run_id' => $run->id,
                    'reason' => $reason,
                    'pending_members' => $pending,
                    'queue_batch_id' => $run->queue_batch_id,
                ]);
                $result['stalled']++;
            });

        return $result;
    }

    public function isAnomalous(SettlementRun $run): bool
    {
        return $this->state($run)['anomalous'];
    }

    /** @return array{anomalous: bool, pending_members: int, failed_jobs: int, pending_jobs: int, reason: string|null} */
    public function state(SettlementRun $run): array
    {
        $pendingMembers = $run->members()->where('outcome', 'pending')->count();
        $failedJobs = 0;
        $pendingJobs = 0;
        $reason = null;
        if ($run->queue_batch_id === null || $run->queue_batch_id === '') {
            $reason = $pendingMembers > 0 ? 'missing_queue_batch_id' : null;
        } else {
            $batch = Bus::findBatch((string) $run->queue_batch_id);
            if ($batch === null) {
                $reason = $pendingMembers > 0 ? 'queue_batch_not_found' : null;
            } else {
                $failedJobs = (int) $batch->failedJobs;
                $pendingJobs = (int) $batch->pendingJobs;
                $reason = $pendingMembers > 0 ? $this->anomalyReason($run, $batch) : null;
            }
        }

        return [
            'anomalous' => $pendingMembers > 0 && $reason !== null,
            'pending_members' => $pendingMembers,
            'failed_jobs' => $failedJobs,
            'pending_jobs' => $pendingJobs,
            'reason' => $reason,
        ];
    }

    private function anomalyReason(SettlementRun $run, mixed $batch = null): ?string
    {
        if ($run->queue_batch_id === null || $run->queue_batch_id === '') {
            return 'missing_queue_batch_id';
        }

        $batch ??= Bus::findBatch((string) $run->queue_batch_id);
        if ($batch === null) {
            return 'queue_batch_not_found';
        }
        if ((int) $batch->failedJobs > 0) {
            return 'queue_batch_has_failed_jobs';
        }
        if ((int) $batch->pendingJobs === 0) {
            return 'queue_batch_finished_with_pending_members';
        }
        if ($run->started_at !== null
            && CarbonImmutable::parse($run->started_at)->addMinutes(self::STALE_AFTER_MINUTES)->isPast()) {
            return 'queue_batch_stale';
        }

        return null;
    }
}
