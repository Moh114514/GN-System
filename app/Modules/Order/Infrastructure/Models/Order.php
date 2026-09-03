<?php

namespace App\Modules\Order\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $customer_id
 * @property int $institution_id
 * @property int|null $agent_id
 * @property int $amount_krw
 * @property string $status
 * @property string $record_status
 * @property Carbon|null $occurred_on
 * @property Carbon|null $completed_on
 * @property Carbon|null $completed_at
 * @property array<string, mixed>|null $business_attribution_snapshot
 * @property string|null $source_return_file_id
 * @property string $completion_precision
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Order extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'completed_on' => 'date',
            'occurred_on' => 'date',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'deleted_at' => 'datetime',
            'business_attribution_snapshot' => 'array',
        ];
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** @return BelongsTo<InstitutionReturnFile, $this> */
    public function sourceReturnFile(): BelongsTo
    {
        return $this->belongsTo(InstitutionReturnFile::class, 'source_return_file_id');
    }
}
