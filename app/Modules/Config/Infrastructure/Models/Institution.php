<?php

namespace App\Modules\Config\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property bool $is_active
 * @property string|null $address
 * @property string|null $contact_name
 * @property string|null $contact_value
 */
class Institution extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return HasMany<InstitutionAlias, $this> */
    public function aliases(): HasMany
    {
        return $this->hasMany(InstitutionAlias::class);
    }
}
