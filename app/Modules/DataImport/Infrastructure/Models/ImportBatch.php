<?php

namespace App\Modules\DataImport\Infrastructure\Models;

use App\Models\User;
use App\Modules\DataImport\Domain\ImportBatchStatus;
use App\Modules\DataImport\Domain\ImportOperationMode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $created_by
 * @property string $kind
 * @property ImportOperationMode $operation_mode
 * @property string|null $operation_reason
 * @property ImportBatchStatus $status
 * @property int $total_rows
 * @property int $valid_rows
 * @property int $warning_rows
 * @property int $error_rows
 * @property array<string, mixed>|null $summary
 * @property Carbon|null $completed_at
 * @property Carbon|null $rollback_expires_at
 * @property Carbon|null $rolled_back_at
 * @property string|null $failure_reason
 * @property string|null $failure_reason_key
 * @property array<string, mixed>|null $failure_reason_parameters
 */
class ImportBatch extends Model
{
    use HasUuids;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ImportBatchStatus::class,
            'operation_mode' => ImportOperationMode::class,
            'summary' => 'array',
            'completed_at' => 'datetime',
            'rollback_expires_at' => 'datetime',
            'rolled_back_at' => 'datetime',
            'failure_reason_parameters' => 'encrypted:array',
        ];
    }

    /** @return HasMany<ImportFile, $this> */
    public function files(): HasMany
    {
        return $this->hasMany(ImportFile::class);
    }

    /** @return HasMany<ImportRow, $this> */
    public function rows(): HasMany
    {
        return $this->hasMany(ImportRow::class);
    }

    /** @return HasMany<ImportIssue, $this> */
    public function issues(): HasMany
    {
        return $this->hasMany(ImportIssue::class, 'import_batch_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function canRollback(): bool
    {
        return $this->status === ImportBatchStatus::Completed
            && $this->rollback_expires_at?->isFuture() === true;
    }
}
