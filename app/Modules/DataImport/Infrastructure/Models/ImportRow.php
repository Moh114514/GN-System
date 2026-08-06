<?php

namespace App\Modules\DataImport\Infrastructure\Models;

use App\Modules\DataImport\Domain\ImportProfile;
use App\Modules\DataImport\Domain\ImportRowStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $import_batch_id
 * @property int $import_file_id
 * @property string|null $sheet_name
 * @property int $source_row
 * @property ImportProfile $profile
 * @property ImportRowStatus $status
 * @property array<string, mixed>|null $raw_payload_encrypted
 * @property array<string, mixed>|null $normalized_data
 * @property array<int, string>|null $errors
 * @property array<string, mixed>|null $resolution
 */
class ImportRow extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'profile' => ImportProfile::class,
            'status' => ImportRowStatus::class,
            'raw_payload_encrypted' => 'encrypted:array',
            'normalized_data' => 'array',
            'errors' => 'array',
            'resolution' => 'array',
        ];
    }

    /** @return BelongsTo<ImportBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }

    /** @return BelongsTo<ImportFile, $this> */
    public function file(): BelongsTo
    {
        return $this->belongsTo(ImportFile::class, 'import_file_id');
    }

    /** @return HasMany<ImportIssue, $this> */
    public function issues(): HasMany
    {
        return $this->hasMany(ImportIssue::class, 'import_row_id');
    }
}
