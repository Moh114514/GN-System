<?php

namespace App\Modules\Settlement\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $agent_id
 * @property int $total_consumption_krw
 * @property int $total_commission_krw
 * @property int $payout_amount_cny_fen
 * @property string $status
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property array<string, mixed>|null $snapshot
 */
class Settlement extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'settled_on' => 'date',
            'reviewed_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'exchange_rate_krw_per_cny' => 'decimal:6',
            'snapshot' => 'array',
        ];
    }
}
