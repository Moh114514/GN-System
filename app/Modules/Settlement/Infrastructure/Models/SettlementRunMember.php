<?php

namespace App\Modules\Settlement\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $settlement_run_id
 * @property int $agent_id
 * @property int|null $settlement_id
 * @property-read Settlement|null $settlement
 * @property string $outcome
 * @property int $attempt_count
 * @property string|null $error_message_key
 * @property array<string, scalar>|null $error_parameters
 * @property Carbon|null $processed_at
 */
class SettlementRunMember extends Model
{
    protected $guarded = [];

    /** @return BelongsTo<SettlementRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(SettlementRun::class, 'settlement_run_id');
    }

    /** @return BelongsTo<Settlement, $this> */
    public function settlement(): BelongsTo
    {
        return $this->belongsTo(Settlement::class);
    }

    protected function casts(): array
    {
        return [
            'error_parameters' => 'array',
            'processed_at' => 'datetime',
        ];
    }
}
