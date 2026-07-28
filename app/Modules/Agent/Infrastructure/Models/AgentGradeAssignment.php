<?php

namespace App\Modules\Agent\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $agent_id
 * @property int $policy_grade_id
 * @property Carbon $effective_month
 * @property int|null $approved_by
 * @property string|null $reason
 */
class AgentGradeAssignment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['effective_month' => 'date'];
    }
}
