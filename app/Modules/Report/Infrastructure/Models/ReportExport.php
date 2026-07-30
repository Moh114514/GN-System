<?php

namespace App\Modules\Report\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

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
