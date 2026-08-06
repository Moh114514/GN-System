<?php

namespace App\Modules\DataImport\Application\Services;

use App\Modules\DataImport\Infrastructure\Models\ImportBatch;
use App\Modules\DataImport\Infrastructure\Models\ImportFile;
use App\Modules\DataImport\Infrastructure\Models\ImportIssue;
use App\Modules\DataImport\Infrastructure\Models\ImportRow;

final class ImportIssueRecorder
{
    public function record(
        ImportBatch $batch,
        string $stage,
        string $severity,
        string $code,
        string $message,
        ?ImportFile $file = null,
        ?ImportRow $row = null,
        ?string $field = null,
        ?array $context = null,
        bool $isIgnorable = false,
    ): ImportIssue {
        $context = array_merge($context ?? [], [
            'file' => $context['file'] ?? $file?->original_name,
        ]);

        return ImportIssue::query()->create([
            'import_batch_id' => $batch->id,
            'import_file_id' => $file?->id ?? $row?->import_file_id,
            'import_row_id' => $row?->id,
            'stage' => $stage,
            'severity' => $severity,
            'code' => $code,
            'profile' => $row?->profile?->value,
            'sheet_name' => $row?->sheet_name,
            'source_row' => $row?->source_row,
            'field' => $field,
            'message' => $message,
            'context_encrypted' => $context === ['file' => null] ? null : $context,
            'is_ignorable' => $isIgnorable,
        ]);
    }

    public function clearRowIssues(ImportBatch $batch): void
    {
        $batch->issues()->whereNotNull('import_row_id')->delete();
    }

    public function syncRows(ImportBatch $batch, ?string $forcedStage = null, bool $clear = true): void
    {
        if ($clear) {
            $this->clearRowIssues($batch);
        }

        $existing = $batch->issues()
            ->whereNotNull('import_row_id')
            ->get(['import_row_id', 'message'])
            ->mapWithKeys(static fn (ImportIssue $issue): array => [($issue->import_row_id ?? 0).'|'.$issue->message => true]);
        foreach ($batch->rows()->with('file')->get() as $row) {
            foreach ($row->errors ?? [] as $message) {
                $message = (string) $message;
                if ($existing->has($row->id.'|'.$message)) {
                    continue;
                }

                $stage = $forcedStage ?? $this->stageFor($row);
                $this->record(
                    $batch,
                    $stage,
                    $row->status->value === 'warning' ? 'warning' : 'error',
                    $this->codeFor($stage),
                    $message,
                    $row->file,
                    $row,
                    null,
                    [
                        'raw_value' => $this->rawValue($row),
                        'normalized_value' => $row->normalized_data,
                    ],
                    $this->isIgnorable($row),
                );
            }
        }
    }

    private function stageFor(ImportRow $row): string
    {
        if ($row->profile?->value === 'settlement_summary') {
            return 'summary_validation';
        }

        if (in_array($row->profile?->value, ['customer_followup', 'monthly_detail'], true)) {
            return 'relation_validation';
        }

        return 'normalization';
    }

    private function codeFor(string $stage): string
    {
        return match ($stage) {
            'relation_validation' => 'relation_unresolved',
            'summary_validation' => 'summary_mismatch',
            default => 'field_validation_failed',
        };
    }

    private function rawValue(ImportRow $row): mixed
    {
        $payload = $row->raw_payload_encrypted ?? [];

        return is_array($payload) && array_key_exists('values', $payload) ? $payload['values'] : $payload;
    }

    private function isIgnorable(ImportRow $row): bool
    {
        $data = $row->normalized_data ?? [];

        return $row->status->value === 'warning'
            && $row->profile?->value === 'customer_followup'
            && ! empty($data['code'])
            && empty($data['institution']);
    }
}
