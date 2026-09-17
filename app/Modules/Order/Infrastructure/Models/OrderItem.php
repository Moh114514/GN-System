<?php

namespace App\Modules\Order\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $order_id
 * @property int|null $treatment_project_id
 * @property string $project_snapshot
 * @property string|null $specification
 * @property string $quantity
 * @property int $unit_price_krw
 * @property int $amount_krw
 * @property string|null $notes
 */
class OrderItem extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
