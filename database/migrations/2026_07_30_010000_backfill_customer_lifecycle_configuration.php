<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $stages = [
            ['key' => 'first_contact', 'name' => '首次接触', 'sort_order' => 10],
            ['key' => 'booking', 'name' => '预约确认', 'sort_order' => 20],
            ['key' => 'arrival', 'name' => '到院接待', 'sort_order' => 30],
            ['key' => 'followup', 'name' => '后续跟进', 'sort_order' => 40],
            ['key' => 'operations', 'name' => '运营管理', 'sort_order' => 50],
        ];

        foreach ($stages as $stage) {
            DB::table('customer_lifecycle_stages')->insertOrIgnore([
                ...$stage,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $stageIds = DB::table('customer_lifecycle_stages')->pluck('id', 'key');
        $statuses = [
            ['key' => 'interested', 'name' => '意向', 'stage' => 'first_contact', 'sort_order' => 10],
            ['key' => 'quoted', 'name' => '已报价', 'stage' => 'first_contact', 'sort_order' => 20],
            ['key' => 'booked', 'name' => '已预约', 'stage' => 'booking', 'sort_order' => 30],
            ['key' => 'arrived', 'name' => '已到院', 'stage' => 'arrival', 'sort_order' => 40],
            ['key' => 'returned_home', 'name' => '已回国', 'stage' => 'followup', 'sort_order' => 50],
            ['key' => 'dormant', 'name' => '沉默待唤醒', 'stage' => 'operations', 'sort_order' => 60],
            ['key' => 'lost', 'name' => '已流失', 'stage' => 'operations', 'sort_order' => 70],
        ];

        foreach ($statuses as $status) {
            DB::table('customer_statuses')->insertOrIgnore([
                'stage_id' => $stageIds[$status['stage']],
                'key' => $status['key'],
                'name' => $status['name'],
                'sort_order' => $status['sort_order'],
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $statusIds = DB::table('customer_statuses')->pluck('id', 'key');
        foreach (array_map(null, array_slice($statuses, 0, -1), array_slice($statuses, 1)) as [$from, $to]) {
            DB::table('customer_status_transitions')->insertOrIgnore([
                'from_status_id' => $statusIds[$from['key']],
                'to_status_id' => $statusIds[$to['key']],
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // These stable machine keys are permanent system baseline data. A rollback
        // must not delete lifecycle records that may already be referenced.
    }
};
