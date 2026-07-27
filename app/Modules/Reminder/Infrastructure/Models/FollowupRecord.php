<?php

namespace App\Modules\Reminder\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

class FollowupRecord extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['followed_up_on' => 'date'];
    }
}
