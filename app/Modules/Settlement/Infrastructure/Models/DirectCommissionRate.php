<?php

namespace App\Modules\Settlement\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $rate_bps
 * @property Carbon $effective_from
 * @property Carbon|null $effective_until
 * @property int|null $created_by
 * @property string $reason
 */
class DirectCommissionRate extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_until' => 'date',
        ];
    }
}
