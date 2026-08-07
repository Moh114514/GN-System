<?php

namespace App\Modules\Report\Application\Services;

use App\Infrastructure\Localization\SupportedLocale;
use App\Modules\Report\Infrastructure\Models\ReportExport;
use Illuminate\Support\Facades\App;
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
        $criteria = $export->criteria_snapshot;
        $locale = SupportedLocale::fromCandidate($criteria['_locale'] ?? null) ?? SupportedLocale::default();
        unset($criteria['_locale']);
        $previousLocale = App::getLocale();
        App::setLocale($locale->value);
        $spreadsheet = null;
        $absolutePath = null;
        try {
            if ($this->search->count($criteria) > max(1, (int) config('reporting.max_export_rows', 50000))) {
                throw new RuntimeException('查询结果超过导出上限，请缩小筛选范围后重试。');
            }

            $rows = $this->search->rows($criteria);
            $spreadsheet = new Spreadsheet;
            $disk = Storage::disk('local');
            $path = "reports/exports/{$export->created_by}/{$export->id}.xlsx";
            $absolutePath = $disk->path($path);
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->fromArray([
                [
                    __('search.page.results.headers.completed_at'),
                    __('search.page.results.headers.customer'),
                    __('search.page.results.headers.agent'),
                    __('search.page.results.headers.project'),
                    __('search.page.results.headers.institution'),
                    __('search.page.results.headers.translator'),
                    __('search.page.results.headers.amount'),
                ],
            ]);
            foreach ($rows as $index => $row) {
                $sheet->fromArray([[
                    $row['completed_at'].($row['completion_precision'] === 'date' ? ' ('.__('search.page.results.date_precision').')' : ''),
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
            if (is_string($absolutePath) && is_file($absolutePath)) {
                @unlink($absolutePath);
            }

            throw $exception;
        } finally {
            $spreadsheet?->disconnectWorksheets();
            App::setLocale($previousLocale);
        }
    }
}
