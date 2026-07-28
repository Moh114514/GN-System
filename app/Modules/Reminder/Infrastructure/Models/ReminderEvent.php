<?php

namespace App\Modules\Reminder\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

class ReminderEvent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['properties' => 'array', 'occurred_at' => 'datetime'];
    }
}
