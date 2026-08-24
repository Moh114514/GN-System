<?php

namespace App\Modules\Report\Application\Services;

use App\Infrastructure\Localization\SupportedLocale;
use App\Modules\Auth\Application\Contracts\AccessContextResolver;
use App\Modules\Report\Infrastructure\Models\ReportExport;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Throwable;

final readonly class ReportSearchExportGenerator
{
    public function __construct(private ReportSearch $search, private AccessContextResolver $access) {}

    public function generate(ReportExport $export): ReportExport
    {
        $export->update([
            'status' => 'generating',
            'failure_reason' => null,
            'failure_reason_key' => null,
            'failure_reason_parameters' => null,
        ]);
        /** @var array<string, mixed> $criteria */
        $criteria = $export->criteria_snapshot;
        $accessSnapshot = $criteria['_access'] ?? null;
        unset($criteria['_access']);
        $context = is_array($accessSnapshot)
            ? $this->access->fromSnapshot($accessSnapshot)
            : $this->access->current();

        return $this->access->using($context, fn (): ReportExport => $this->generateInContext($export, $criteria));
    }

    /** @param array<string, mixed> $criteria */
    private function generateInContext(ReportExport $export, array $criteria): ReportExport
    {
        $locale = SupportedLocale::fromCandidate($criteria['_locale'] ?? null) ?? SupportedLocale::default();
        unset($criteria['_locale']);
        $previousLocale = App::getLocale();
        App::setLocale($locale->value);
        $spreadsheet = null;
        $absolutePath = null;
        try {
            if ($this->search->count($criteria) > max(1, (int) config('reporting.max_export_rows', 50000))) {
                throw new RuntimeException(__('search.page.exports.failure_reasons.too_many_rows'));
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
                throw new RuntimeException(__('search.page.exports.failure_reasons.directory_unwritable'));
            }

            (new Xlsx($spreadsheet))->save($absolutePath);
            if (! is_file($absolutePath)) {
                throw new RuntimeException(__('search.page.exports.failure_reasons.file_missing'));
            }

            $sha256 = hash_file('sha256', $absolutePath);
            if ($sha256 === false) {
                throw new RuntimeException(__('search.page.exports.failure_reasons.checksum_failed'));
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
