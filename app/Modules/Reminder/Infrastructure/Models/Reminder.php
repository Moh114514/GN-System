<?php

namespace App\Modules\Reminder\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $customer_id
 * @property int|null $assigned_to
 * @property int|null $created_by
 * @property string $status
 * @property string $notification_status
 * @property string $title
 * @property string|null $suggestion
 * @property string|null $notes
 * @property Carbon $due_at
 * @property array<string, mixed>|null $recurrence
 * @property Carbon|null $completed_at
 */
class Reminder extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'recurrence' => 'array',
            'notified_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
