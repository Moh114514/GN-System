<?php

namespace App\Modules\Settlement\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property array<string, string|array{message_key: string, parameters: array<string, scalar>}>|null $errors
 * @property string $status
 * @property int $total_agents
 * @property int $processed_agents
 * @property int $existing_agents
 * @property array<int, int>|null $existing_agent_ids
 * @property int $failed_agents
 * @property int $total_consumption_krw
 * @property int $total_commission_krw
 * @property string|null $progress_key
 * @property string $notification_status
 */
class SettlementRun extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    /** @return HasMany<Settlement, $this> */
    public function settlements(): HasMany
    {
        return $this->hasMany(Settlement::class)
            ->orderBy('agent_id')
            ->orderBy('id');
    }

    /** @return HasMany<SettlementRunMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(SettlementRunMember::class)
            ->orderBy('agent_id')
            ->orderBy('id');
    }

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'errors' => 'array',
            'existing_agent_ids' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'notified_at' => 'datetime',
        ];
    }
}
