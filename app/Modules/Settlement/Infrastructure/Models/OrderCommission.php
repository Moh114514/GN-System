<?php

namespace App\Modules\Settlement\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $order_id
 * @property int $agent_id
 * @property int $rate_bps
 * @property int $amount_krw
 * @property array<string, mixed> $rule_snapshot
 */
class OrderCommission extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['rule_snapshot' => 'array'];
    }
}
