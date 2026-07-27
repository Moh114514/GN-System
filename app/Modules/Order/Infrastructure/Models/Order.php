<?php

namespace App\Modules\Order\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $customer_id
 * @property int $institution_id
 * @property string $channel
 * @property int|null $agent_id
 * @property int|null $direct_sales_source_id
 * @property int $amount_krw
 */
class Order extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['completed_on' => 'date'];
    }
}
