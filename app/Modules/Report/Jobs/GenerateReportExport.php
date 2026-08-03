<?php

namespace App\Modules\Report\Jobs;

use App\Modules\Report\Application\Services\ReportSearchExportGenerator;
use App\Modules\Report\Infrastructure\Models\ReportExport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class GenerateReportExport implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60, 300];

    public function __construct(public string $exportId) {}

    public function handle(ReportSearchExportGenerator $generator): void
    {
        $export = ReportExport::query()->findOrFail($this->exportId);
        if ($export->expires_at->isPast()) {
            $export->update(['status' => 'expired']);

            return;
        }
        $generator->generate($export);
    }

    public function failed(Throwable $exception): void
    {
        ReportExport::query()->whereKey($this->exportId)->update([
            'status' => 'failed',
            'failure_reason' => $exception->getMessage(),
        ]);
    }
}
