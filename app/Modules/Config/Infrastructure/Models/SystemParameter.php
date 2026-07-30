<?php

namespace App\Modules\Config\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

class SystemParameter extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }
}
