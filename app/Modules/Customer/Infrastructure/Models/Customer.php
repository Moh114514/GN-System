<?php

namespace App\Modules\Customer\Infrastructure\Models;

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
 * @property string $original_channel
 * @property int|null $source_agent_id
 * @property int|null $source_direct_sales_id
 * @property int|null $current_status_id
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
