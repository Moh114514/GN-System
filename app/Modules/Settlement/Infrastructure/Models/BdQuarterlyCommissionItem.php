<?php

namespace App\Modules\Settlement\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $quarterly_commission_id
 * @property int $order_id
 * @property int|null $bd_user_id
 * @property Carbon $occurred_on
 * @property array<string, mixed> $attribution_snapshot
 * @property array<string, mixed> $rule_snapshot
 */
class BdQuarterlyCommissionItem extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'occurred_on' => 'date',
            'attribution_snapshot' => 'array',
            'rule_snapshot' => 'array',
        ];
    }

    /** @return BelongsTo<BdQuarterlyCommission, $this> */
    public function quarterlyCommission(): BelongsTo
    {
        return $this->belongsTo(BdQuarterlyCommission::class, 'quarterly_commission_id');
    }
}
