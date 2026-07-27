<?php

namespace App\Modules\Customer\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $code
 * @property string|null $legacy_code
 * @property string $name
 * @property string $original_channel
 * @property int|null $source_agent_id
 * @property int|null $source_direct_sales_id
 * @property int|null $current_status_id
 * @property string|null $import_batch_id
 */
class Customer extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'wechat_added_on' => 'date',
        ];
    }
}
