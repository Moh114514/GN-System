<?php

namespace App\Modules\Customer\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $changed_at
 * @property int|null $from_status_id
 * @property int $to_status_id
 * @property int|null $changed_by
 * @property string|null $reason
 */
class CustomerStatusHistory extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['changed_at' => 'datetime'];
    }
}
