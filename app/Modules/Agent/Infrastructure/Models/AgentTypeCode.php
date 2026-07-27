<?php

namespace App\Modules\Agent\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 */
class AgentTypeCode extends Model
{
    protected $guarded = [];
}
