<?php

namespace App\Modules\Settlement\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $agent_id
 * @property int|null $source_order_id
 * @property int|null $order_commission_id
 * @property int|null $original_settlement_id
 * @property int|null $target_settlement_id
 * @property string $adjustment_key
 * @property Carbon $eligible_period_start
 * @property int $consumption_delta_krw
 * @property int $commission_delta_krw
 * @property string $reason
 * @property array<string, mixed> $snapshot
 */
class SettlementAdjustment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'eligible_period_start' => 'date',
            'snapshot' => 'array',
        ];
    }
}
