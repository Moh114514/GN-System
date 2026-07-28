<?php

namespace App\Modules\Settlement\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $agent_id
 * @property int|null $institution_id
 * @property int $rate_bps
 * @property Carbon $effective_from
 * @property Carbon|null $effective_until
 * @property string $reason
 * @property int|null $approved_by
 */
class AgentCommissionOverride extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_until' => 'date',
        ];
    }
}
