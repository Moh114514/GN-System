<?php

namespace App\Modules\Agent\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $policy_system_id
 * @property string $name
 * @property int $monthly_threshold_krw
 * @property int $sort_order
 * @property bool $is_active
 */
class PolicyGrade extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
