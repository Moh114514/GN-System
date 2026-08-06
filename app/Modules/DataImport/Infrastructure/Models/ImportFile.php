<?php

namespace App\Modules\DataImport\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $import_batch_id
 * @property string $original_name
 * @property string $extension
 * @property string|null $mime_type
 * @property int $size_bytes
 * @property string $sha256
 * @property string $encrypted_path
 * @property string|null $profile
 * @property string $status
 * @property array<string, mixed>|null $preflight
 */
class ImportFile extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'preflight' => 'array',
        ];
    }

    /** @return BelongsTo<ImportBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }

    /** @return HasMany<ImportIssue, $this> */
    public function issues(): HasMany
    {
        return $this->hasMany(ImportIssue::class, 'import_file_id');
    }
}
