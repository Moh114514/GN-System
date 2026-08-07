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
 * @property string $generation_status
 * @property Carbon|null $generated_at
 * @property int $item_count
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property Carbon|null $settled_on
 * @property Carbon|null $reviewed_at
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $exchange_rate_quoted_at
 * @property int|null $settled_by
 * @property string|null $exchange_rate_quote_source
 * @property string|null $exchange_rate_quote_error_key
 * @property array<string, scalar>|null $exchange_rate_quote_error_parameters
 * @property array<string, mixed>|null $snapshot
 * @property bool $exchange_rate_manual_override
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
            'generated_at' => 'datetime',
            'exchange_rate_krw_per_cny' => 'decimal:6',
            'exchange_rate_quoted_at' => 'datetime',
            'exchange_rate_quote_attempted_at' => 'datetime',
            'exchange_rate_manual_override' => 'boolean',
            'exchange_rate_quote_error_parameters' => 'array',
            'snapshot' => 'array',
        ];
    }
}
