<?php

namespace App\Modules\Order\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $institution_id
 * @property string $template_key
 * @property int $version
 * @property array<int, string> $columns
 * @property bool $is_active
 */
class InstitutionFormTemplate extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'columns' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<InstitutionReturnFile, $this> */
    public function returnFiles(): HasMany
    {
        return $this->hasMany(InstitutionReturnFile::class, 'template_id');
    }
}
