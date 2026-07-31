<?php

namespace App\Modules\Order\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $customer_id
 * @property int $institution_id
 * @property string $channel
 * @property int|null $agent_id
 * @property int|null $direct_sales_source_id
 * @property int $amount_krw
 * @property string $status
 * @property Carbon|null $completed_on
 * @property Carbon|null $completed_at
 * @property string $completion_precision
 */
class Order extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'completed_on' => 'date',
            'completed_at' => 'datetime',
        ];
    }
}
