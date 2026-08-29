<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Agent\Application\Services\AgentConfigurationCoordinator;
use App\Modules\Agent\Infrastructure\Models\PolicyGrade;
use App\Modules\Agent\Infrastructure\Models\PolicySystem;
use App\Modules\Config\Infrastructure\Models\Institution;
use App\Modules\Settlement\Infrastructure\Models\CommissionRule;
use Database\Seeders\PhaseTwoReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConfigurationUserExperienceTest extends TestCase
{
    use RefreshDatabase;

    public function test_configuration_pages_use_translated_titles_and_status_labels(): void
    {
        $admin = User::factory()->superAdmin()->withTwoFactor()->create();

        $this->actingAs($admin)->get(route('configuration.history'))
            ->assertOk()
            ->assertSee(__('config.configuration_history.title'))
            ->assertSee(__('config.configuration_history.empty'));

        $this->actingAs($admin)->get(route('configuration.catalog'))
            ->assertOk()
            ->assertSee(__('config.catalog.title'))
            ->assertSee(__('config.catalog.parameters_heading'));

        $this->actingAs($admin)->get(route('configuration.data-maintenance'))
            ->assertOk()
            ->assertSee(__('config.data_maintenance.title'))
            ->assertSee(__('config.data_maintenance.reference_import.title'));
    }

    public function test_customer_status_configuration_has_baseline_data_and_field_explanations(): void
    {
        $admin = User::factory()->superAdmin()->withTwoFactor()->create();

        $this->actingAs($admin)
            ->get(route('customer-statuses.index'))
            ->assertOk()
            ->assertSee('客户生命周期')
            ->assertSee('已预约')
            ->assertSee('施术结束')
            ->assertSee('系统固定结构：顺序、启用状态、所属阶段和流转路径不可修改')
            ->assertDontSee('wire:model="statuses.0.sort_order"', false)
            ->assertDontSee('wire:model="statuses.0.to_status_ids"', false)
            ->assertDontSee('尚未初始化生命周期阶段')
            ->assertDontSee('尚未初始化客户状态');

        $this->assertDatabaseHas('customer_lifecycle_stages', ['key' => 'customer_lifecycle']);
        $this->assertDatabaseHas('customer_statuses', ['key' => 'booked']);
        $this->assertDatabaseHas('customer_status_transitions', [
            'from_status_id' => DB::table('customer_statuses')->where('key', 'arrived')->value('id'),
            'to_status_id' => DB::table('customer_statuses')->where('key', 'treatment_completed')->value('id'),
        ]);
    }

    public function test_lifecycle_reference_seeder_is_idempotent_and_keeps_the_three_state_baseline(): void
    {
        $this->seed(PhaseTwoReferenceDataSeeder::class);

        $this->assertSame(1, DB::table('customer_lifecycle_stages')->count());
        $this->assertSame(3, DB::table('customer_statuses')->count());
        $this->assertSame(2, DB::table('customer_status_transitions')->count());
    }

    public function test_agent_configuration_explains_manual_grade_fields_and_supports_view_sorting(): void
    {
        $admin = User::factory()->superAdmin()->withTwoFactor()->create();
        $policy = PolicySystem::query()->create(['name' => 'UAT 政策', 'is_active' => true]);
        $lowerGrade = PolicyGrade::query()->create([
            'policy_system_id' => $policy->id,
            'name' => '低门槛等级',
            'sort_order' => 20,
            'is_active' => true,
        ]);
        $higherGrade = PolicyGrade::query()->create([
            'policy_system_id' => $policy->id,
            'name' => '高门槛等级',
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
            ->assertSee('费率：高到低')
            ->assertSee('只改变当前列表的查看顺序')
            ->assertSee('<th title="数字越小，默认显示顺序越靠前。">排序</th>', false);

        $coordinator = app(AgentConfigurationCoordinator::class);
        $sortDescending = $coordinator->state(gradeSort: 'sort_desc');
        $this->assertSame(
            ['低门槛等级', '高门槛等级'],
            array_column($sortDescending['grades'], 'name'),
        );

        $rateDescending = $coordinator->state(ruleSort: 'rate_desc');
        $this->assertSame([1500, 500], array_column($rateDescending['rules'], 'rate_bps'));
    }
}
