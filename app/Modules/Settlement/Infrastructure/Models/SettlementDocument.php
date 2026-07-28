<?php

namespace App\Modules\Settlement\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

class SettlementDocument extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['content_snapshot' => 'array', 'generated_at' => 'datetime'];
    }
}
