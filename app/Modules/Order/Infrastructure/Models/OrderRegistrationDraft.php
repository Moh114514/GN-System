<?php

namespace App\Modules\Order\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $customer_id
 * @property int|null $appointment_id
 * @property int|null $institution_id
 * @property int|null $completed_order_id
 * @property int $created_by
 * @property array<string, mixed> $payload
 * @property Carbon|null $completed_at
 */
class OrderRegistrationDraft extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
