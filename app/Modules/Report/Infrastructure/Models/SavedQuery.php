<?php

namespace App\Modules\Report\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

class SavedQuery extends Model
{
    protected $table = 'report_saved_queries';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['criteria' => 'array'];
    }
}
