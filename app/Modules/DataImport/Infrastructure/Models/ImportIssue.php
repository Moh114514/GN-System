<?php

namespace App\Modules\DataImport\Infrastructure\Models;

use App\Modules\DataImport\Domain\ImportIssueSeverity;
use App\Modules\DataImport\Domain\ImportIssueStage;
use App\Modules\DataImport\Domain\ImportProfile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $import_batch_id
 * @property int|null $import_file_id
 * @property int|null $import_row_id
 * @property ImportIssueStage $stage
 * @property ImportIssueSeverity $severity
 * @property string $code
 * @property ImportProfile|null $profile
 * @property string|null $sheet_name
 * @property int|null $source_row
 * @property string|null $field
 * @property string $message
 * @property string|null $message_key
 * @property array<string, mixed>|null $message_parameters
 * @property array<string, mixed>|null $context_encrypted
 * @property bool $is_ignorable
 */
class ImportIssue extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stage' => ImportIssueStage::class,
            'severity' => ImportIssueSeverity::class,
            'profile' => ImportProfile::class,
            'message_parameters' => 'encrypted:array',
            'context_encrypted' => 'encrypted:array',
            'is_ignorable' => 'boolean',
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

    /** @return BelongsTo<ImportRow, $this> */
    public function row(): BelongsTo
    {
        return $this->belongsTo(ImportRow::class, 'import_row_id');
    }
}
