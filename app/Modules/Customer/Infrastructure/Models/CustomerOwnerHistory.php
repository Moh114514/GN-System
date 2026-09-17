<?php

namespace App\Modules\Customer\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/** @property CarbonImmutable $effective_at */
class CustomerOwnerHistory extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['effective_at' => 'immutable_datetime'];
    }
}
