<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('customers', 'treatment_completed_at')) {
            Schema::table('customers', function (Blueprint $table): void {
                $table->timestamp('treatment_completed_at')->nullable()->after('current_status_id');
            });
        }

        DB::transaction(function (): void {
            DB::table('customers')->update(['current_status_id' => null, 'treatment_completed_at' => null]);
            $legacyTemplateKeys = ['post_treatment_1', 'post_treatment_90', 'post_treatment_180', 'existing_customer', 'dormant_customer', 'repurchase'];
            $legacyTemplateIds = Schema::hasTable('reminder_templates')
                ? DB::table('reminder_templates')->whereIn('system_key', $legacyTemplateKeys)->pluck('id')
                : collect();
            $legacyRuleIds = collect();
            if (Schema::hasTable('reminder_rules')) {
                $legacyStatusIds = DB::table('customer_statuses')
                    ->whereNotIn('key', ['booked', 'arrived', 'treatment_completed'])
                    ->pluck('id');
                $legacyRuleIds = DB::table('reminder_rules')
                    ->where('is_system', true)
                    ->get(['id', 'trigger_type', 'trigger_config'])
                    ->filter(function (object $rule) use ($legacyStatusIds): bool {
                        $config = is_array($rule->trigger_config)
                            ? $rule->trigger_config
                            : (json_decode((string) $rule->trigger_config, true) ?: []);

                        return ($rule->trigger_type === 'fixed_cycle'
                                && in_array((int) ($config['interval_days'] ?? 0), [90, 180], true))
                            || ($rule->trigger_type === 'date_offset'
                                && ($config['field'] ?? null) === 'completed_on'
                                && in_array((int) ($config['offset_days'] ?? 0), [1, 90, 180], true))
                            || ($rule->trigger_type === 'status_change'
                                && in_array((int) ($config['status_id'] ?? 0), $legacyStatusIds->all(), true));
                    })
                    ->pluck('id');
            }
            if (Schema::hasTable('reminders')) {
                $legacyReminderIds = DB::table('reminders')
                    ->where(function ($query) use ($legacyRuleIds, $legacyTemplateIds): void {
                        $query
                            ->where(function ($query): void {
                                $query->where('source_type', 'system')->where('reminder_type', 'post_treatment');
                            })
                            ->orWhereIn('rule_id', $legacyRuleIds->all())
                            ->orWhereIn('template_id', $legacyTemplateIds->all());
                    })
                    ->pluck('id');
                if ($legacyReminderIds->isNotEmpty()) {
                    if (Schema::hasTable('reminder_events')) {
                        DB::table('reminder_events')->whereIn('reminder_id', $legacyReminderIds)->delete();
                    }
                    DB::table('reminders')->whereIn('id', $legacyReminderIds)->delete();
                }
            }
            if (Schema::hasTable('reminder_rules') && $legacyRuleIds->isNotEmpty()) {
                DB::table('reminder_rules')->whereIn('id', $legacyRuleIds)->delete();
            }
            if (Schema::hasTable('reminder_templates')) {
                DB::table('reminder_templates')->whereIn('id', $legacyTemplateIds->all())->delete();
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
        if (Schema::hasColumn('customers', 'treatment_completed_at')) {
            Schema::table('customers', function (Blueprint $table): void {
                $table->dropColumn('treatment_completed_at');
            });
        }
    }
};
