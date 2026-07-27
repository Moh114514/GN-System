<?php

namespace App\Modules\Customer\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $customer_id
 * @property string $type
 * @property string $value_encrypted
 * @property string $lookup_hash
 */
class CustomerContact extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['value_encrypted' => 'encrypted'];
    }
}
