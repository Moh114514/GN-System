<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('reminders') || ! Schema::hasTable('reminder_events')) {
            return;
        }

        $now = now();
        $reminders = DB::table('reminders')
            ->where('reminder_type', 'appointment')
            ->whereIn('status', ['pending', 'snoozed', 'transferred'])
            ->get(['id', 'status', 'notification_status', 'appointment_id']);

        foreach ($reminders as $reminder) {
            DB::table('reminders')->where('id', $reminder->id)->update([
                'status' => 'cancelled',
                'notification_status' => $reminder->notification_status === 'sent' ? 'sent' : 'cancelled',
                'updated_at' => $now,
            ]);
            DB::table('reminder_events')->insert([
                'reminder_id' => $reminder->id,
                'event' => 'cancelled',
                'actor_id' => null,
                'properties' => json_encode([
                    'reason' => 'appointment_reminder_policy_changed',
                    'appointment_id' => $reminder->appointment_id,
                    'before' => $reminder->status,
                    'after' => 'cancelled',
                ], JSON_THROW_ON_ERROR),
                'occurred_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Historical reminders are intentionally not reactivated on rollback.
    }
};
