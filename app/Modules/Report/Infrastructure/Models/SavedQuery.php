<?php

namespace App\Modules\Report\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $created_by
 * @property string $name
 * @property string $scope
 * @property array<string, int|string|null> $criteria
 * @property string $sort_field
 * @property string $sort_direction
 */
class SavedQuery extends Model
{
    protected $table = 'report_saved_queries';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['criteria' => 'array'];
    }
}
