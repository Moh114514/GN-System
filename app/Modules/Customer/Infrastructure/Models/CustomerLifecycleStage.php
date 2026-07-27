<?php

namespace App\Modules\Customer\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerLifecycleStage extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
