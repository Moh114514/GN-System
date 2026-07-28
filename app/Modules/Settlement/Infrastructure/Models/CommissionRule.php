<?php

namespace App\Modules\Settlement\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $policy_grade_id
 * @property int $institution_id
 * @property int $rate_bps
 * @property Carbon $effective_month
 * @property bool $is_active
 */
class CommissionRule extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'effective_month' => 'date',
            'is_active' => 'boolean',
        ];
    }
}
