<?php

namespace App\Modules\Settlement\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $effective_from
 * @property int|null $generation_day
 */
class SettlementConfiguration extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['effective_from' => 'date'];
    }
}
