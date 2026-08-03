<?php

namespace App\Modules\Report\Application\Services;

use App\Modules\Report\Infrastructure\Models\ReportExport;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final readonly class ReportSearchExportGenerator
{
    public function __construct(private ReportSearch $search) {}

    public function generate(ReportExport $export): ReportExport
    {
        $export->update(['status' => 'generating', 'failure_reason' => null]);
        $rows = $this->search->rows($export->criteria_snapshot);
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

        return $export->refresh();
    }
}
