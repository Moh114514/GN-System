<?php

namespace App\Modules\Report\Application\Services;

use App\Models\User;
use App\Modules\Report\Infrastructure\Models\ReportExport;
use App\Modules\Report\Jobs\GenerateReportExport;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

final readonly class ReportExportManager
{
    public function __construct(
        private ReportSearch $search,
        private ReportSearchExportGenerator $generator,
    ) {}

    /** @param array<string, int|string|null> $criteria */
    public function queueSearch(User $user, array $criteria): ReportExport
    {
        $export = $this->createSearchExport($user, $criteria, 'queued');
        GenerateReportExport::dispatch($export->id);

        return $export;
    }

    /** @param array<string, int|string|null> $criteria */
    public function startSearch(User $user, array $criteria): ReportExport
    {
        $total = $this->search->count($criteria);
        $maxRows = max(1, (int) config('reporting.max_export_rows', 50000));

        if ($total > $maxRows) {
            $export = $this->createSearchExport($user, $criteria, 'failed');
            $export->update([
                'failure_reason' => '查询结果超过导出上限，请缩小筛选范围后重试。',
            ]);

            return $export->refresh();
        }

        if ($total > max(0, (int) config('reporting.max_sync_export_rows', 2000))) {
            return $this->queueSearch($user, $criteria);
        }

        return $this->generateSearch($user, $criteria);
    }

    /** @param array<string, int|string|null> $criteria */
    public function generateSearch(User $user, array $criteria): ReportExport
    {
        $export = $this->createSearchExport($user, $criteria, 'generating');

        try {
            return $this->generator->generate($export);
        } catch (Throwable $exception) {
            report($exception);
            $export->update([
                'status' => 'failed',
                'failure_reason' => '导出文件生成失败，请检查存储权限后重试。',
            ]);
        }

        return $export->refresh();
    }

    public function retry(User $user, string $id): void
    {
        $export = ReportExport::query()->where('created_by', $user->id)->findOrFail($id);
        abort_unless($export->status === 'failed' && $export->expires_at->isFuture(), 422);
        $export->update([
            'status' => 'queued',
            'failure_reason' => null,
            'path' => null,
            'sha256' => null,
            'generated_at' => null,
        ]);
        GenerateReportExport::dispatch($export->id);
    }

    /** @return Collection<int, ReportExport> */
    public function recent(User $user): Collection
    {
        return ReportExport::query()
            ->where('created_by', $user->id)
            ->latest()
            ->limit(20)
            ->get();
    }

    /** @param array<string, int|string|null> $criteria */
    private function createSearchExport(User $user, array $criteria, string $status): ReportExport
    {
        return ReportExport::query()->create([
            'created_by' => $user->id,
            'kind' => 'search',
            'format' => 'xlsx',
            'status' => $status,
            'criteria_snapshot' => $this->search->queryData($criteria)->toArray(),
            'expires_at' => now()->addHours(24),
        ]);
    }
}
