<?php

namespace App\Modules\Report\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $created_by
 * @property string $kind
 * @property string $format
 * @property string $status
 * @property array<string, int|string|null> $criteria_snapshot
 * @property array<string, mixed>|null $data_snapshot
 * @property string|null $failure_reason
 * @property string|null $path
 * @property string|null $sha256
 * @property Carbon|null $generated_at
 * @property Carbon $expires_at
 */
class ReportExport extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'criteria_snapshot' => 'array',
            'data_snapshot' => 'array',
            'generated_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
