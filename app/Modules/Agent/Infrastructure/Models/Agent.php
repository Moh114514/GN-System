<?php

namespace App\Modules\Agent\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $agent_type_code_id
 * @property string $code
 * @property string|null $legacy_code
 * @property string $name
 * @property string|null $business_role
 * @property string|null $contact_name
 * @property string|null $contact_value
 * @property string $cooperation_status
 * @property Carbon|null $cooperation_started_on
 * @property Carbon|null $cooperation_ended_on
 * @property string|null $import_batch_id
 */
class Agent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'contact_value' => 'encrypted',
            'cooperation_started_on' => 'date',
            'cooperation_ended_on' => 'date',
        ];
    }
}
