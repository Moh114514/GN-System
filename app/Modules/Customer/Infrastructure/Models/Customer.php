<?php

namespace App\Modules\Customer\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $code
 * @property string|null $legacy_code
 * @property string $name
 * @property int|null $source_agent_id
 * @property int|null $current_status_id
 * @property CarbonImmutable|null $treatment_completed_at
 * @property CarbonImmutable|null $arrived_at
 * @property string|null $import_batch_id
 * @property Carbon|null $birth_date
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Customer extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'wechat_added_on' => 'date',
            'treatment_completed_at' => 'immutable_datetime',
            'arrived_at' => 'immutable_datetime',
        ];
    }

    /** @return HasOne<CustomerContact, $this> */
    public function primaryContact(): HasOne
    {
        return $this->hasOne(CustomerContact::class)->where('is_primary', true);
    }

    /** @return HasOne<CustomerIdentityDocument, $this> */
    public function identityDocument(): HasOne
    {
        return $this->hasOne(CustomerIdentityDocument::class);
    }

    /** @return BelongsTo<CustomerStatus, $this> */
    public function currentStatus(): BelongsTo
    {
        return $this->belongsTo(CustomerStatus::class, 'current_status_id');
    }

    /** @return HasMany<CustomerStatusHistory, $this> */
    public function statusHistories(): HasMany
    {
        return $this->hasMany(CustomerStatusHistory::class);
    }
}
