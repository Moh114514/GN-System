<?php

namespace App\Modules\Order\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $customer_id
 * @property int $institution_id
 * @property string|null $scheduled_at
 * @property string $status
 */
class Appointment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['scheduled_at' => 'datetime'];
    }
}
