<?php

namespace App\Modules\Settlement\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

class AgentGradeEvaluation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'period' => 'date',
            'consecutive_failure_count' => 'integer',
        ];
    }
}
