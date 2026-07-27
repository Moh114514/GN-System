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
            ['key' => 'first_contact', 'name' => '首次接触', 'sort_order' => 10],
            ['key' => 'booking', 'name' => '预约确认', 'sort_order' => 20],
            ['key' => 'arrival', 'name' => '到院接待', 'sort_order' => 30],
            ['key' => 'followup', 'name' => '后续跟进', 'sort_order' => 40],
            ['key' => 'operations', 'name' => '运营管理', 'sort_order' => 50],
        ];

        foreach ($stages as $stage) {
            DB::table('customer_lifecycle_stages')->updateOrInsert(
                ['key' => $stage['key']],
                [...$stage, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            );
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
    }
}
