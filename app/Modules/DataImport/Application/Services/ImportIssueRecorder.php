<?php

namespace App\Modules\DataImport\Application\Services;

use App\Modules\DataImport\Infrastructure\Models\ImportBatch;
use App\Modules\DataImport\Infrastructure\Models\ImportFile;
use App\Modules\DataImport\Infrastructure\Models\ImportIssue;
use App\Modules\DataImport\Infrastructure\Models\ImportRow;

final class ImportIssueRecorder
{
    /**
     * @param  array<string, mixed>|null  $context
     * @param  array<string, mixed>  $messageParameters
     */
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
        ?string $messageKey = null,
        array $messageParameters = [],
    ): ImportIssue {
        $context ??= [];
        $context['file'] ??= $file?->original_name;

        [$resolvedMessageKey, $resolvedMessageParameters] = $this->resolveMessage($code, $message, $messageKey, $messageParameters);

        return ImportIssue::query()->create([
            'import_batch_id' => $batch->id,
            'import_file_id' => $file->id ?? $row?->import_file_id,
            'import_row_id' => $row?->id,
            'stage' => $stage,
            'severity' => $severity,
            'code' => $code,
            'profile' => $row?->profile->value,
            'sheet_name' => $row?->sheet_name,
            'source_row' => $row?->source_row,
            'field' => $field,
            'message' => $message,
            'message_key' => $resolvedMessageKey,
            'message_parameters' => $resolvedMessageParameters === [] ? null : $resolvedMessageParameters,
            'context_encrypted' => $context === ['file' => null] ? null : $context,
            'is_ignorable' => $isIgnorable,
        ]);
    }

    /**
     * @param  array<string, mixed>  $messageParameters
     * @return array{string, array<string, mixed>}
     */
    private function resolveMessage(string $code, string $message, ?string $messageKey, array $messageParameters): array
    {
        if ($messageKey !== null || $messageParameters !== []) {
            return [$messageKey ?? "imports.errors.{$code}", $messageParameters];
        }

        if (preg_match('/^机构代码“(?<institution_code>.+)”不存在。$/u', $message, $matches) === 1) {
            return ['imports.errors.institution_code_missing', ['institution_code' => $matches['institution_code']]];
        }

        return ["imports.errors.{$code}", []];
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
        if ($row->profile->value === 'settlement_summary') {
            return 'summary_validation';
        }

        if (in_array($row->profile->value, ['customer_followup', 'monthly_detail'], true)) {
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

        return array_key_exists('values', $payload) ? $payload['values'] : $payload;
    }

    private function isIgnorable(ImportRow $row): bool
    {
        $data = $row->normalized_data ?? [];

        return $row->status->value === 'warning'
            && $row->profile->value === 'customer_followup'
            && ! empty($data['code'])
            && empty($data['institution']);
    }
}
