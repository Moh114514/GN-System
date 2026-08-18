<?php

namespace App\Modules\Config\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationDelivery extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'recipients' => 'array',
            'attempts' => 'integer',
            'sent_at' => 'datetime',
        ];
    }
}
