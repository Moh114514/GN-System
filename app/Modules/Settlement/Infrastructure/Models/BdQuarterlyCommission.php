<?php

namespace App\Modules\Settlement\Infrastructure\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property Carbon $quarter_start
 * @property Carbon $quarter_end
 * @property string $status
 * @property-read Collection<int, BdQuarterlyCommissionItem> $items
 * @property-read Collection<int, BdCommissionAdjustment> $adjustments
 */
class BdQuarterlyCommission extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'quarter_start' => 'date',
            'quarter_end' => 'date',
            'rule_snapshot' => 'array',
            'generated_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    /** @return HasMany<BdQuarterlyCommissionItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(BdQuarterlyCommissionItem::class, 'quarterly_commission_id');
    }

    /** @return HasMany<BdCommissionAdjustment, $this> */
    public function adjustments(): HasMany
    {
        return $this->hasMany(BdCommissionAdjustment::class, 'quarterly_commission_id');
    }
}
