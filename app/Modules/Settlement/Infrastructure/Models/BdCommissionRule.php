<?php

namespace App\Modules\Settlement\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $base_type
 * @property string $currency
 * @property int $rate_bps
 * @property Carbon $effective_from
 */
class BdCommissionRule extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'metadata' => 'array',
        ];
    }
}
