<?php

namespace App\Modules\DataImport\Application\Services;

use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\DataImport\Domain\ImportBatchStatus;
use App\Modules\DataImport\Domain\ImportRowStatus;
use App\Modules\DataImport\Infrastructure\Models\ImportRow;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final readonly class ImportRowAdjudicator
{
    public function __construct(private AuditRecorder $audit) {}

    public function ignore(ImportRow $row, int $userId, string $reason): void
    {
        if ($row->batch->status !== ImportBatchStatus::NeedsReview) {
            throw new RuntimeException('只有待处理批次可以执行人工裁决。');
        }

        $issues = $row->issues()->get();
        if ($issues->isEmpty() || $issues->contains(static fn ($issue): bool => ! $issue->is_ignorable)) {
            throw new RuntimeException('Import issue is not ignorable.');
        }

        $row->update([
            'status' => ImportRowStatus::Ignored,
            'resolution' => [
                'action' => 'ignored',
                'reason' => trim($reason),
                'resolved_by' => $userId,
                'resolved_at' => now()->toIso8601String(),
            ],
        ]);

        $counts = $row->batch->rows()
            ->select('status', DB::raw('COUNT(*) AS aggregate'))
            ->groupBy('status')
            ->pluck('aggregate', 'status');
        $warnings = (int) ($counts[ImportRowStatus::Warning->value] ?? 0)
            + (int) ($counts[ImportRowStatus::DuplicateCandidate->value] ?? 0);
        $errors = (int) ($counts[ImportRowStatus::Error->value] ?? 0);

        $row->batch->update([
            'valid_rows' => (int) ($counts[ImportRowStatus::Valid->value] ?? 0),
            'warning_rows' => $warnings,
            'error_rows' => $errors,
            'status' => ($warnings + $errors) === 0
                ? ImportBatchStatus::Validated
                : ImportBatchStatus::NeedsReview,
        ]);

        $this->audit->record('人工裁决导入行', [
            'import_batch_id' => $row->import_batch_id,
            'import_row_id' => $row->id,
            'action' => 'ignored',
            'reason' => trim($reason),
        ], $userId);
    }
}
