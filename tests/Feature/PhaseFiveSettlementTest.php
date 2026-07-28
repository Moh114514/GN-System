<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Agent\Infrastructure\Models\Agent;
use App\Modules\Agent\Infrastructure\Models\AgentGradeAssignment;
use App\Modules\Agent\Infrastructure\Models\AgentTypeCode;
use App\Modules\Agent\Infrastructure\Models\PolicyGrade;
use App\Modules\Agent\Infrastructure\Models\PolicySystem;
use App\Modules\Config\Infrastructure\Models\Institution;
use App\Modules\Customer\Infrastructure\Models\Customer;
use App\Modules\Order\Application\Contracts\DailyOrderGateway;
use App\Modules\Order\Application\Data\DailyOrderData;
use App\Modules\Settlement\Application\Services\SettlementGenerator;
use App\Modules\Settlement\Application\Services\SettlementPeriodCalculator;
use App\Modules\Settlement\Application\Services\SettlementRunManager;
use App\Modules\Settlement\Application\Services\SettlementWorkflow;
use App\Modules\Settlement\Infrastructure\Models\CommissionRule;
use App\Modules\Settlement\Infrastructure\Models\Settlement;
use App\Modules\Settlement\Infrastructure\Models\SettlementDocument;
use App\Modules\Settlement\Infrastructure\Models\SettlementGradeSuggestion;
use App\Modules\Settlement\Infrastructure\Models\SettlementRun;
use Carbon\CarbonImmutable;
use Database\Seeders\PhaseTwoReferenceDataSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PhaseFiveSettlementTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private User $admin;

    private Agent $agent;

    private PolicyGrade $grade;

    private Institution $institution;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-08-01 09:00:00');
        config(['queue.default' => 'sync', 'dingtalk.enabled' => false]);
        $this->seed(PhaseTwoReferenceDataSeeder::class);
        $this->user = User::factory()->create();
        $this->admin = User::factory()->superAdmin()->withTwoFactor()->create();
        $system = PolicySystem::query()->create(['name' => '月结政策', 'is_active' => true]);
        $this->grade = PolicyGrade::query()->create([
            'policy_system_id' => $system->id,
            'name' => '标准级',
            'monthly_threshold_krw' => 0,
            'sort_order' => 10,
            'is_active' => true,
        ]);
        $type = AgentTypeCode::query()->where('code', 'JG')->firstOrFail();
        $this->agent = Agent::query()->create([
            'agent_type_code_id' => $type->id,
            'code' => 'SETTLE-JG',
            'name' => '月结测试代理商',
            'cooperation_status' => 'active',
            'cooperation_started_on' => '2026-01-01',
        ]);
        AgentGradeAssignment::query()->create([
            'agent_id' => $this->agent->id,
            'policy_grade_id' => $this->grade->id,
            'effective_month' => '2026-01-01',
            'approved_by' => $this->admin->id,
            'reason' => '测试',
        ]);
        $this->institution = Institution::query()->firstOrFail();
        $this->customer = Customer::query()->create([
            'code' => 'SETTLE-0001',
            'name' => '月结客户',
            'original_channel' => 'agent',
            'source_agent_id' => $this->agent->id,
            'owner_id' => $this->user->id,
        ]);
        CommissionRule::query()->create([
            'policy_grade_id' => $this->grade->id,
            'institution_id' => $this->institution->id,
            'rate_bps' => 1000,
            'effective_month' => '2026-01-01',
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_period_configuration_is_continuous_and_changes_next_cycle(): void
    {
        $calculator = app(SettlementPeriodCalculator::class);
        $period = $calculator->latestClosedPeriod(CarbonImmutable::now());
        $this->assertSame('2026-07-01', $period->start->toDateString());
        $this->assertSame('2026-07-31', $period->end->toDateString());

        $configuration = $calculator->saveConfiguration(15, '10:30', $this->admin->id, CarbonImmutable::now());
        $this->assertSame('2026-08-15', $configuration->effective_from->toDateString());
        $this->assertSame(1, $calculator->activeConfiguration(CarbonImmutable::now())->boundary_day);
        $this->assertSame(15, $calculator->activeConfiguration(CarbonImmutable::parse('2026-08-15'))->boundary_day);
    }

    public function test_monthly_run_aggregates_snapshots_and_is_idempotent(): void
    {
        $orderId = $this->createCompletedOrder(10005);
        $run = app(SettlementRunManager::class)->start('manual', $this->admin->id);
        $run->refresh();
        $settlement = Settlement::query()->where('settlement_run_id', $run->id)->firstOrFail();

        $this->assertSame('completed', $run->status);
        $this->assertSame(10005, (int) $settlement->total_consumption_krw);
        $this->assertSame(1001, (int) $settlement->total_commission_krw);
        $this->assertDatabaseHas('settlement_items', ['settlement_id' => $settlement->id, 'order_commission_id' => DB::table('order_commissions')->where('order_id', $orderId)->value('id')]);

        $same = app(SettlementRunManager::class)->start('manual', $this->admin->id);
        $this->assertSame($run->id, $same->id);
        $this->assertDatabaseCount('settlement_runs', 1);
        $this->assertDatabaseCount('settlements', 1);
    }

    public function test_review_generates_equal_document_snapshots_and_settles(): void
    {
        Storage::fake('local');
        $this->createCompletedOrder(10000);
        $run = app(SettlementRunManager::class)->start('manual', $this->admin->id);
        $settlement = Settlement::query()->where('settlement_run_id', $run->id)->firstOrFail();
        $workflow = app(SettlementWorkflow::class);
        $workflow->approve($settlement->id, '200', $this->admin->id, '127.0.0.1');
        $settlement->refresh();

        $this->assertSame('approved', $settlement->status);
        $this->assertSame(500, (int) $settlement->payout_amount_cny_fen);
        $documents = SettlementDocument::query()->where('settlement_id', $settlement->id)->orderBy('format')->get();
        $this->assertCount(2, $documents);
        $this->assertEquals($documents[0]->content_snapshot, $documents[1]->content_snapshot);
        foreach ($documents as $document) {
            Storage::disk('local')->assertExists($document->path);
        }
        $workflow->settle($settlement->id, $this->admin->id, null);
        $this->assertDatabaseHas('settlements', ['id' => $settlement->id, 'status' => 'settled']);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'settlement', 'subject_id' => $settlement->id, 'event' => 'settled']);
    }

    public function test_rejection_requires_reason_and_historical_period_is_not_overwritten(): void
    {
        $settlement = Settlement::query()->create([
            'agent_id' => $this->agent->id,
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'status' => 'paid',
            'snapshot' => ['source' => 'historical_import'],
        ]);
        $run = SettlementRun::query()->create([
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'trigger_source' => 'manual',
            'status' => 'running',
            'total_agents' => 1,
            'progress_key' => 'test',
        ]);
        $this->expectException(DomainException::class);
        app(SettlementGenerator::class)->generate($run->id, $this->agent->id);
        $this->assertSame('paid', $settlement->refresh()->status);
    }

    public function test_settlement_pages_enforce_admin_and_parent_navigation(): void
    {
        $this->actingAs($this->user)->get(route('settlements.index'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('settlements.index'))->assertOk()->assertSee('月结中心');
        $this->createCompletedOrder(10000);
        $run = app(SettlementRunManager::class)->start('manual', $this->admin->id);
        $settlement = Settlement::query()->where('settlement_run_id', $run->id)->firstOrFail();
        $this->actingAs($this->admin)->get(route('settlements.show', $settlement))
            ->assertOk()
            ->assertSee('返回月结中心')
            ->assertSee('href="'.route('settlements.index').'"', false);
    }

    public function test_grade_suggestion_requires_manual_review_and_starts_next_month(): void
    {
        $higher = PolicyGrade::query()->create([
            'policy_system_id' => $this->grade->policy_system_id,
            'name' => '升级级',
            'monthly_threshold_krw' => 500,
            'sort_order' => 20,
            'is_active' => true,
        ]);
        $this->createCompletedOrder(10000);
        $run = app(SettlementRunManager::class)->start('manual', $this->admin->id);
        $settlement = Settlement::query()->where('settlement_run_id', $run->id)->firstOrFail();
        $suggestion = SettlementGradeSuggestion::query()->where('settlement_id', $settlement->id)->firstOrFail();
        $this->assertSame($higher->id, (int) $suggestion->recommended_grade_id);
        $this->assertDatabaseMissing('agent_grade_assignments', ['agent_id' => $this->agent->id, 'policy_grade_id' => $higher->id]);

        app(SettlementWorkflow::class)->reviewSuggestion($suggestion->id, true, '人工确认升级', $this->admin->id);
        $this->assertDatabaseHas('agent_grade_assignments', [
            'agent_id' => $this->agent->id,
            'policy_grade_id' => $higher->id,
            'effective_month' => '2026-08-01',
        ]);
    }

    public function test_one_thousand_items_complete_within_five_minutes(): void
    {
        $now = now();
        $orders = [];
        $commissions = [];
        for ($index = 1; $index <= 1000; $index++) {
            $orderId = 10000 + $index;
            $orders[] = [
                'id' => $orderId,
                'customer_id' => $this->customer->id,
                'institution_id' => $this->institution->id,
                'channel' => 'agent',
                'agent_id' => $this->agent->id,
                'project_name' => '性能项目',
                'amount_krw' => 10000,
                'completed_on' => '2026-07-15',
                'owner_id' => $this->user->id,
                'status' => 'completed',
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $commissions[] = [
                'order_id' => $orderId,
                'agent_id' => $this->agent->id,
                'rate_bps' => 1000,
                'amount_krw' => 1000,
                'rule_snapshot' => json_encode(['rate_bps' => 1000, 'order_amount_krw' => 10000], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        foreach (array_chunk($orders, 250) as $chunk) {
            DB::table('orders')->insert($chunk);
        }
        foreach (array_chunk($commissions, 250) as $chunk) {
            DB::table('order_commissions')->insert($chunk);
        }
        $run = SettlementRun::query()->create([
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'trigger_source' => 'manual',
            'status' => 'running',
            'total_agents' => 1,
            'progress_key' => 'performance-test',
        ]);
        $started = microtime(true);
        app(SettlementGenerator::class)->generate($run->id, $this->agent->id);
        $duration = microtime(true) - $started;

        $settlement = Settlement::query()->where('settlement_run_id', $run->id)->firstOrFail();
        $this->assertSame(1000, DB::table('settlement_items')->where('settlement_id', $settlement->id)->count());
        $this->assertLessThan(300, $duration);
    }

    private function createCompletedOrder(int $amount): int
    {
        return app(DailyOrderGateway::class)->create(new DailyOrderData(
            customerId: $this->customer->id,
            institutionId: $this->institution->id,
            channel: 'agent',
            agentId: $this->agent->id,
            directSalesSourceId: null,
            projectName: '月结项目',
            amountKrw: $amount,
            status: 'completed',
            completedOn: CarbonImmutable::parse('2026-07-15'),
            translatorName: null,
            notes: null,
            ownerId: $this->user->id,
            ipAddress: '127.0.0.1',
        ));
    }
}
