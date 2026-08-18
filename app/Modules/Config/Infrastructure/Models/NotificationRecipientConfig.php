<?php

namespace App\Modules\Config\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationRecipientConfig extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}
