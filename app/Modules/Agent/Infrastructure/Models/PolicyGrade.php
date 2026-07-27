<?php

namespace App\Modules\Agent\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $policy_system_id
 * @property string $name
 */
class PolicyGrade extends Model
{
    protected $guarded = [];
}
