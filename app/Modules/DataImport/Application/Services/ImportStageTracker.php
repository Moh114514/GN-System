<?php

namespace App\Modules\DataImport\Application\Services;

use App\Modules\DataImport\Infrastructure\Models\ImportBatch;

final class ImportStageTracker
{
    /** @var array<string, string> */
    private const DEFAULT_STATUSES = [
        'file_detection' => 'pending',
        'field_validation' => 'pending',
        'normalization' => 'pending',
        'relation_validation' => 'pending',
        'business_validation' => 'pending',
        'summary_validation' => 'pending',
        'dry_run' => 'not_started',
        'commit' => 'not_started',
    ];

    public function initialize(ImportBatch $batch): void
    {
        $summary = $batch->summary ?? [];
        $summary['stages'] = array_map(
            static fn (string $status): array => ['status' => $status],
            self::DEFAULT_STATUSES,
        );
        $batch->update(['summary' => $summary]);
    }

    /** @param array<string, int|string> $metrics */
    public function update(ImportBatch $batch, string $stage, string $status, array $metrics = []): void
    {
        $summary = $batch->summary ?? [];
        $stages = $summary['stages'] ?? self::DEFAULT_STATUSES;
        $stages[$stage] = ['status' => $status, ...$metrics];
        $summary['stages'] = $stages;
        $batch->update(['summary' => $summary]);
    }

    /** @return array<string, string> */
    public static function statuses(): array
    {
        return self::DEFAULT_STATUSES;
    }
}
