<?php

namespace App\Modules\Reminder\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property string $trigger_type
 * @property array<string, mixed> $trigger_config
 * @property string $scope_type
 * @property array<string, mixed> $scope_config
 * @property string $title
 * @property string|null $suggestion
 * @property int $priority
 * @property bool $is_active
 */
class ReminderRule extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'trigger_config' => 'array',
            'scope_config' => 'array',
            'is_active' => 'boolean',
            'is_system' => 'boolean',
        ];
    }
}
