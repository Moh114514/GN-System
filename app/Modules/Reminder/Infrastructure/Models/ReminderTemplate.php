<?php

namespace App\Modules\Reminder\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property string $title
 * @property string|null $suggestion
 * @property string|null $system_key
 * @property string $default_trigger_type
 * @property array<string, mixed> $default_trigger_config
 * @property bool $is_system
 * @property bool $is_active
 * @property int|null $owner_id
 */
class ReminderTemplate extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'default_trigger_config' => 'array',
            'is_system' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
