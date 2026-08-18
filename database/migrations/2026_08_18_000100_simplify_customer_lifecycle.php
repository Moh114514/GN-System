<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->timestamp('treatment_completed_at')->nullable()->after('current_status_id');
        });

        DB::transaction(function (): void {
            DB::table('customers')->update(['current_status_id' => null, 'treatment_completed_at' => null]);
            if (Schema::hasTable('reminder_events') && Schema::hasTable('reminders')) {
                $systemReminderIds = DB::table('reminders')->where('source_type', 'system')->pluck('id');
                if ($systemReminderIds->isNotEmpty()) {
                    DB::table('reminder_events')->whereIn('reminder_id', $systemReminderIds)->delete();
                    DB::table('reminders')->whereIn('id', $systemReminderIds)->delete();
                }
            }
            if (Schema::hasTable('reminder_templates')) {
                DB::table('reminder_templates')
                    ->whereIn('system_key', ['post_treatment_1', 'post_treatment_90', 'post_treatment_180', 'existing_customer', 'dormant_customer', 'repurchase'])
                    ->delete();
            }
            if (Schema::hasTable('reminder_rules')) {
                $legacyRuleIds = DB::table('reminder_rules')
                    ->where('is_system', true)
                    ->get(['id', 'trigger_type', 'trigger_config'])
                    ->filter(function (object $rule): bool {
                        $config = is_array($rule->trigger_config)
                            ? $rule->trigger_config
                            : (json_decode((string) $rule->trigger_config, true) ?: []);

                        return $rule->trigger_type === 'fixed_cycle'
                            && in_array((int) ($config['interval_days'] ?? 0), [90, 180], true);
                    })
                    ->pluck('id');
                if ($legacyRuleIds->isNotEmpty()) {
                    DB::table('reminder_rules')->whereIn('id', $legacyRuleIds)->delete();
                }
            }
            DB::table('customer_status_histories')->delete();
            DB::table('customer_status_transitions')->delete();
            DB::table('customer_statuses')->delete();
            DB::table('customer_lifecycle_stages')->delete();

            $stageId = DB::table('customer_lifecycle_stages')->insertGetId([
                'key' => 'customer_lifecycle',
                'name' => '客户生命周期',
                'sort_order' => 10,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $statuses = [
                ['key' => 'booked', 'name' => '已预约', 'sort_order' => 10],
                ['key' => 'arrived', 'name' => '已到院', 'sort_order' => 20],
                ['key' => 'treatment_completed', 'name' => '施术结束', 'sort_order' => 30],
            ];
            $statusIds = [];
            foreach ($statuses as $status) {
                $statusIds[$status['key']] = DB::table('customer_statuses')->insertGetId([
                    ...$status,
                    'stage_id' => $stageId,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            DB::table('customer_status_transitions')->insert([
                [
                    'from_status_id' => $statusIds['booked'],
                    'to_status_id' => $statusIds['arrived'],
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'from_status_id' => $statusIds['arrived'],
                    'to_status_id' => $statusIds['treatment_completed'],
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn('treatment_completed_at');
        });
    }
};
