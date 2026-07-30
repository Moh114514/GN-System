<?php

namespace App\Modules\Report\Jobs;

use App\Modules\Report\Application\Services\ReportSearch;
use App\Modules\Report\Infrastructure\Models\ReportExport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Throwable;

final class GenerateReportExport implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60, 300];

    public function __construct(public string $exportId) {}

    public function handle(ReportSearch $search): void
    {
        $export = ReportExport::query()->findOrFail($this->exportId);
        if ($export->expires_at->isPast()) {
            $export->update(['status' => 'expired']);

            return;
        }
        $export->update(['status' => 'generating', 'failure_reason' => null]);
        $rows = $search->rows($export->criteria_snapshot);
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([
            ['成交时间', '客户', '代理商', '施术项目', '机构', '翻译姓名', '成交金额 KRW'],
        ]);
        foreach ($rows as $index => $row) {
            $sheet->fromArray([[
                $row['completed_at'].($row['completion_precision'] === 'date' ? '（日期精度）' : ''),
                $row['customer'],
                $row['agent'],
                $row['project'],
                $row['institution'],
                $row['translator'],
                $row['amount_krw'],
            ]], null, 'A'.($index + 2));
        }
        foreach (range('A', 'G') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        $directory = "reports/exports/{$export->created_by}";
        Storage::disk('local')->makeDirectory($directory);
        $path = "{$directory}/{$export->id}.xlsx";
        (new Xlsx($spreadsheet))->save(Storage::disk('local')->path($path));
        $spreadsheet->disconnectWorksheets();
        $export->update([
            'status' => 'completed',
            'path' => $path,
            'sha256' => hash_file('sha256', Storage::disk('local')->path($path)),
            'generated_at' => now(),
        ]);
    }

    public function failed(Throwable $exception): void
    {
        ReportExport::query()->whereKey($this->exportId)->update([
            'status' => 'failed',
            'failure_reason' => $exception->getMessage(),
        ]);
    }
}
