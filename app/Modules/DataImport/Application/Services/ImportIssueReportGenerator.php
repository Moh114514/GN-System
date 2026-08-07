<?php

namespace App\Modules\DataImport\Application\Services;

use App\Modules\DataImport\Infrastructure\Models\ImportBatch;
use App\Modules\DataImport\Infrastructure\Models\ImportIssue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

final class ImportIssueReportGenerator
{
    public function __construct(private readonly ImportIssueMessagePresenter $messages) {}

    /** @var array<int, string> */
    private const HEADERS = [
        'stage',
        'severity',
        'error code',
        'file',
        'worksheet',
        'source row',
        'profile',
        'agent code',
        'agent name',
        'customer code',
        'field',
        'raw value',
        'normalized value',
        'message',
        'recommended action',
    ];

    /**
     * Generate an issue report and return its path relative to the local disk.
     *
     * The batch argument is used only as an identifier. All report data comes
     * from import_issues, including denormalized display values in its context.
     */
    public function generate(string|ImportBatch $batch): string
    {
        $batchId = $this->batchId($batch);
        $issues = ImportIssue::query()
            ->where('import_batch_id', $batchId)
            ->orderBy('id')
            ->get([
                'id',
                'import_file_id',
                'stage',
                'severity',
                'code',
                'profile',
                'sheet_name',
                'source_row',
                'field',
                'message',
                'message_key',
                'message_parameters',
                'context_encrypted',
            ]);

        $spreadsheet = new Spreadsheet;
        $disk = Storage::disk('local');
        $directory = "reports/import-issues/{$batchId}";
        $path = "{$directory}/".Str::uuid().'.xlsx';
        $absolutePath = $disk->path($path);

        try {
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('import issues');
            $this->writeRow($sheet, 1, array_map(
                fn (string $header): string => __('imports.issue_report.headers.'.$header),
                self::HEADERS,
            ));

            foreach ($issues as $index => $issue) {
                $context = is_array($issue->context_encrypted) ? $issue->context_encrypted : [];
                $normalizedValue = $this->contextValue($context, 'normalized_value');
                $row = [
                    $this->stageLabel($issue->stage),
                    $this->severityLabel($issue->severity),
                    $issue->code,
                    $this->contextValue($context, 'file', 'file_name', 'filename', 'original_name')
                        ?? $issue->import_file_id,
                    $issue->sheet_name,
                    $issue->source_row,
                    $this->profileLabel($issue->profile),
                    $this->contextValue($context, 'agent_code')
                        ?? $this->nestedContextValue($normalizedValue, 'agent', 'code')
                        ?? (is_array($normalizedValue) ? ($normalizedValue['agent_code'] ?? null) : null),
                    $this->contextValue($context, 'agent_name')
                        ?? $this->nestedContextValue($normalizedValue, 'agent', 'name'),
                    $this->contextValue($context, 'customer_code')
                        ?? (is_array($normalizedValue) ? ($normalizedValue['customer_code'] ?? null) : null),
                    $issue->field,
                    $this->contextValue($context, 'raw_value'),
                    $normalizedValue,
                    $this->messages->present($issue),
                    $this->contextValue($context, 'recommended_action', 'recommendation'),
                ];

                $this->writeRow($sheet, $index + 2, $row);
            }

            $lastRow = max(1, $issues->count() + 1);
            $sheet->freezePane('A2');
            $sheet->setAutoFilter("A1:O{$lastRow}");
            $sheet->getStyle('A1:O1')->getFont()->setBold(true);
            $sheet->getStyle("A2:O{$lastRow}")->getAlignment()->setWrapText(true);
            foreach (range('A', 'O') as $column) {
                $sheet->getColumnDimension($column)->setAutoSize(true);
            }

            $disk->makeDirectory($directory);
            $reportDirectory = dirname($absolutePath);
            if (! is_dir($reportDirectory) || ! is_writable($reportDirectory)) {
                throw new \RuntimeException('导入问题报告目录不可写。');
            }

            (new Xlsx($spreadsheet))->save($absolutePath);
            if (! is_file($absolutePath)) {
                throw new \RuntimeException('导入问题报告文件未生成。');
            }

            return $path;
        } catch (Throwable $exception) {
            if (is_file($absolutePath)) {
                @unlink($absolutePath);
            }

            throw $exception;
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    /**
     * Generate an issue report and return it as a private download response.
     */
    public function download(string|ImportBatch $batch): BinaryFileResponse
    {
        $batchId = $this->batchId($batch);
        $path = $this->generate($batchId);

        return response()
            ->download(Storage::disk('local')->path($path), __('imports.issue_report.filename', ['batch' => $batchId]))
            ->deleteFileAfterSend();
    }

    private function batchId(string|ImportBatch $batch): string
    {
        return $batch instanceof ImportBatch ? (string) $batch->getKey() : $batch;
    }

    /**
     * @param  array<int, mixed>  $values
     */
    private function writeRow(Worksheet $sheet, int $row, array $values): void
    {
        foreach ($values as $index => $value) {
            $column = $this->columnName($index + 1);
            $sheet->setCellValueExplicit(
                "{$column}{$row}",
                $this->stringValue($value),
                DataType::TYPE_STRING,
            );
        }
    }

    private function columnName(int $number): string
    {
        $column = '';
        while ($number > 0) {
            $remainder = ($number - 1) % 26;
            $column = chr(65 + $remainder).$column;
            $number = intdiv($number - 1, 26);
        }

        return $column;
    }

    private function stringValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function enumValue(mixed $value): mixed
    {
        return $value instanceof \BackedEnum ? $value->value : $value;
    }

    private function stageLabel(mixed $value): string
    {
        $value = $this->enumValue($value);

        return is_string($value) ? (string) __('imports.stages.names.'.$value) : '';
    }

    private function severityLabel(mixed $value): string
    {
        $value = $this->enumValue($value);

        return is_string($value) ? (string) __('imports.severities.'.$value) : '';
    }

    private function profileLabel(mixed $value): string
    {
        $value = $this->enumValue($value);

        return is_string($value) ? (string) __('imports.profiles.'.$value) : '';
    }

    /** @param array<string, mixed> $context */
    private function contextValue(array $context, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $context)) {
                return $context[$key];
            }
        }

        return null;
    }

    private function nestedContextValue(mixed $context, string $parent, string $key): mixed
    {
        return is_array($context) && is_array($context[$parent] ?? null)
            ? ($context[$parent][$key] ?? null)
            : null;
    }
}
