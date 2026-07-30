<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Agent\Application\Services\AgentConfigurationCoordinator;
use App\Modules\Agent\Infrastructure\Models\PolicyGrade;
use App\Modules\Agent\Infrastructure\Models\PolicySystem;
use App\Modules\Config\Infrastructure\Models\Institution;
use App\Modules\Settlement\Infrastructure\Models\CommissionRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConfigurationUserExperienceTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_status_configuration_has_baseline_data_and_field_explanations(): void
    {
        $admin = User::factory()->superAdmin()->withTwoFactor()->create();

        $this->actingAs($admin)
            ->get(route('customer-statuses.index'))
            ->assertOk()
            ->assertSee('首次接触')
            ->assertSee('意向')
            ->assertSee('已报价')
            ->assertSee('排序数字越小，生命周期阶段越靠前')
            ->assertSee('已有历史数据不会被删除')
            ->assertDontSee('尚未初始化生命周期阶段')
            ->assertDontSee('尚未初始化客户状态');

        $this->assertDatabaseHas('customer_lifecycle_stages', ['key' => 'first_contact']);
        $this->assertDatabaseHas('customer_statuses', ['key' => 'interested']);
        $this->assertDatabaseHas('customer_status_transitions', [
            'from_status_id' => DB::table('customer_statuses')->where('key', 'interested')->value('id'),
            'to_status_id' => DB::table('customer_statuses')->where('key', 'quoted')->value('id'),
        ]);
    }

    public function test_lifecycle_baseline_migration_is_idempotent_and_preserves_configuration(): void
    {
        DB::table('customer_lifecycle_stages')
            ->where('key', 'first_contact')
            ->update(['name' => '自定义首次接触', 'sort_order' => 99, 'is_active' => false]);

        $migration = require database_path('migrations/2026_07_30_010000_backfill_customer_lifecycle_configuration.php');
        $migration->up();

        $this->assertDatabaseHas('customer_lifecycle_stages', [
            'key' => 'first_contact',
            'name' => '自定义首次接触',
            'sort_order' => 99,
            'is_active' => false,
        ]);
        $this->assertSame(5, DB::table('customer_lifecycle_stages')->count());
        $this->assertSame(7, DB::table('customer_statuses')->count());
        $this->assertSame(6, DB::table('customer_status_transitions')->count());
    }

    public function test_agent_configuration_explains_fields_and_supports_view_sorting(): void
    {
        $admin = User::factory()->superAdmin()->withTwoFactor()->create();
        $policy = PolicySystem::query()->create(['name' => 'UAT 政策', 'is_active' => true]);
        $lowerGrade = PolicyGrade::query()->create([
            'policy_system_id' => $policy->id,
            'name' => '低门槛等级',
            'monthly_threshold_krw' => 100_000,
            'sort_order' => 20,
            'is_active' => true,
        ]);
        $higherGrade = PolicyGrade::query()->create([
            'policy_system_id' => $policy->id,
            'name' => '高门槛等级',
            'monthly_threshold_krw' => 500_000,
            'sort_order' => 10,
            'is_active' => true,
        ]);
        $institution = Institution::query()->create([
            'code' => 'UAT001',
            'name' => 'UAT 机构',
            'is_active' => true,
        ]);
        CommissionRule::query()->create([
            'policy_grade_id' => $lowerGrade->id,
            'institution_id' => $institution->id,
            'rate_bps' => 500,
            'effective_month' => '2026-08-01',
            'is_active' => true,
        ]);
        CommissionRule::query()->create([
            'policy_grade_id' => $higherGrade->id,
            'institution_id' => $institution->id,
            'rate_bps' => 1500,
            'effective_month' => '2026-09-01',
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->get(route('agent-configuration.index'))
            ->assertOk()
            ->assertSee('查看排序')
            ->assertSee('数字越小，在所属体系和默认列表中越靠前')
            ->assertSee('月门槛：高到低')
            ->assertSee('费率：高到低')
            ->assertSee('只改变当前列表的查看顺序')
            ->assertSee('<th title="数字越小，默认显示顺序越靠前。">排序</th>', false);

        $coordinator = app(AgentConfigurationCoordinator::class);
        $thresholdDescending = $coordinator->state(gradeSort: 'threshold_desc');
        $this->assertSame(
            ['高门槛等级', '低门槛等级'],
            array_column($thresholdDescending['grades'], 'name'),
        );

        $rateDescending = $coordinator->state(ruleSort: 'rate_desc');
        $this->assertSame([1500, 500], array_column($rateDescending['rules'], 'rate_bps'));
    }
}
