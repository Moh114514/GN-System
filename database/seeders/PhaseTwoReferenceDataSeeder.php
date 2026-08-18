<?php

namespace Database\Seeders;

use App\Modules\Agent\Infrastructure\Models\AgentTypeCode;
use App\Modules\Config\Infrastructure\Models\Institution;
use App\Modules\Config\Infrastructure\Models\InstitutionAlias;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PhaseTwoReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['code' => 'JG', 'name' => '机构'],
            ['code' => 'GT', 'name' => '个体户'],
            ['code' => 'KR', 'name' => '韩国代理'],
        ] as $type) {
            AgentTypeCode::query()->updateOrCreate(
                ['code' => $type['code']],
                ['name' => $type['name'], 'is_system' => true, 'is_active' => true],
            );
        }

        foreach ([
            ['code' => 'BLANCHE', 'name' => 'Blanche 布朗徐牙科（首尔江南）', 'aliases' => ['Blanche', 'Blanche 齿科']],
            ['code' => 'DOD', 'name' => 'dod 皮肤科', 'aliases' => ['dod']],
            ['code' => 'GRAYCITY', 'name' => 'Graycity 纹绣', 'aliases' => ['Graycity']],
        ] as $definition) {
            $institution = Institution::query()->updateOrCreate(
                ['code' => $definition['code']],
                ['name' => $definition['name'], 'is_active' => true],
            );

            foreach ($definition['aliases'] as $alias) {
                InstitutionAlias::query()->updateOrCreate(
                    ['alias' => $alias],
                    ['institution_id' => $institution->id],
                );
            }
        }

        $stages = [
            ['key' => 'customer_lifecycle', 'name' => '客户生命周期', 'sort_order' => 10],
        ];

        foreach ($stages as $stage) {
            DB::table('customer_lifecycle_stages')->updateOrInsert(
                ['key' => $stage['key']],
                [...$stage, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            );
        }

        $stageIds = DB::table('customer_lifecycle_stages')->pluck('id', 'key');
        $statuses = [
            ['key' => 'booked', 'name' => '已预约', 'stage' => 'customer_lifecycle', 'sort_order' => 10],
            ['key' => 'arrived', 'name' => '已到院', 'stage' => 'customer_lifecycle', 'sort_order' => 20],
            ['key' => 'treatment_completed', 'name' => '施术结束', 'stage' => 'customer_lifecycle', 'sort_order' => 30],
        ];

        foreach ($statuses as $status) {
            DB::table('customer_statuses')->updateOrInsert(
                ['key' => $status['key']],
                [
                    'stage_id' => $stageIds[$status['stage']],
                    'name' => $status['name'],
                    'sort_order' => $status['sort_order'],
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }

        if (DB::getSchemaBuilder()->hasTable('customer_status_transitions')) {
            $statusIds = DB::table('customer_statuses')->pluck('id', 'key');
            foreach (array_map(null, array_slice($statuses, 0, -1), array_slice($statuses, 1)) as [$from, $to]) {
                DB::table('customer_status_transitions')->updateOrInsert(
                    [
                        'from_status_id' => $statusIds[$from['key']],
                        'to_status_id' => $statusIds[$to['key']],
                    ],
                    ['is_active' => true, 'created_at' => now(), 'updated_at' => now()],
                );
            }
        }
    }
}
