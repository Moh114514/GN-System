<?php

namespace App\Modules\Config\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

class InternalNotification extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }
}
