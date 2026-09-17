<?php

namespace App\Modules\Settlement\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BdCommissionAdjustment extends Model
{
    protected $guarded = [];

    /** @return BelongsTo<BdQuarterlyCommission, $this> */
    public function quarterlyCommission(): BelongsTo
    {
        return $this->belongsTo(BdQuarterlyCommission::class, 'quarterly_commission_id');
    }
}
