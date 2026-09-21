<?php

namespace App\Modules\Settlement\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $order_id
 * @property int|null $owner_id
 * @property int $rate_bps
 * @property int $order_amount_krw
 * @property int $commission_amount_krw
 * @property Carbon $completed_at
 * @property string $status
 * @property array<string, mixed> $rule_snapshot
 * @property Carbon|null $voided_at
 * @property int|null $voided_by
 * @property string|null $void_reason
 */
class DirectOrderCommission extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
            'rule_snapshot' => 'array',
            'voided_at' => 'datetime',
        ];
    }
}
