<?php

namespace App\Modules\Report\Application\Services;

use App\Modules\Report\Infrastructure\Models\ReportExport;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Throwable;

final readonly class ReportSearchExportGenerator
{
    public function __construct(private ReportSearch $search) {}

    public function generate(ReportExport $export): ReportExport
    {
        $export->update(['status' => 'generating', 'failure_reason' => null]);
        if ($this->search->count($export->criteria_snapshot) > max(1, (int) config('reporting.max_export_rows', 50000))) {
            throw new RuntimeException('查询结果超过导出上限，请缩小筛选范围后重试。');
        }

        $rows = $this->search->rows($export->criteria_snapshot);
        $spreadsheet = new Spreadsheet;
        $disk = Storage::disk('local');
        $path = "reports/exports/{$export->created_by}/{$export->id}.xlsx";
        $absolutePath = $disk->path($path);

        try {
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

            $directory = dirname($absolutePath);
            $disk->makeDirectory(dirname($path));
            if (! is_dir($directory) || ! is_writable($directory)) {
                throw new RuntimeException('导出目录不可写，请检查应用运行用户和存储权限。');
            }

            (new Xlsx($spreadsheet))->save($absolutePath);
            if (! is_file($absolutePath)) {
                throw new RuntimeException('导出文件未生成，请检查存储权限。');
            }

            $sha256 = hash_file('sha256', $absolutePath);
            if ($sha256 === false) {
                throw new RuntimeException('导出文件校验失败。');
            }

            $export->update([
                'status' => 'completed',
                'path' => $path,
                'sha256' => $sha256,
                'generated_at' => now(),
            ]);

            return $export->refresh();
        } catch (Throwable $exception) {
            if (is_file($absolutePath)) {
                @unlink($absolutePath);
            }

            throw $exception;
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }
}
