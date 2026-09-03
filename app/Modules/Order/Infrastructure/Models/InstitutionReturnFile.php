<?php

namespace App\Modules\Order\Infrastructure\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int|null $institution_id
 * @property int|null $template_id
 * @property int|null $customer_id
 * @property string|null $form_uuid
 * @property string $original_name
 * @property string $extension
 * @property string|null $mime_type
 * @property int $size_bytes
 * @property string $sha256
 * @property string $encrypted_path
 * @property array<string, mixed>|null $metadata
 * @property string|null $integrity_signature
 * @property string $status
 * @property string|null $failure_code
 * @property string|null $failure_reason
 * @property int|null $uploaded_by
 * @property Carbon $uploaded_at
 * @property Carbon|null $processed_at
 */
class InstitutionReturnFile extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'uploaded_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
        ];
    }

    /** @return HasOne<Order, $this> */
    public function order(): HasOne
    {
        return $this->hasOne(Order::class, 'source_return_file_id');
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
