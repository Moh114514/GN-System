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
use App\Modules\Settlement\Application\Services\ExchangeRateQuoteService;
use App\Modules\Settlement\Application\Services\SettlementGenerator;
use App\Modules\Settlement\Application\Services\SettlementNotificationDispatcher;
use App\Modules\Settlement\Application\Services\SettlementPeriodCalculator;
use App\Modules\Settlement\Application\Services\SettlementRunFailureReader;
use App\Modules\Settlement\Application\Services\SettlementRunFailureReportGenerator;
use App\Modules\Settlement\Application\Services\SettlementRunManager;
use App\Modules\Settlement\Application\Services\SettlementWorkflow;
use App\Modules\Settlement\Infrastructure\Models\CommissionRule;
use App\Modules\Settlement\Infrastructure\Models\Settlement;
use App\Modules\Settlement\Infrastructure\Models\SettlementDocument;
use App\Modules\Settlement\Infrastructure\Models\SettlementGradeSuggestion;
use App\Modules\Settlement\Infrastructure\Models\SettlementRun;
use App\Modules\Settlement\Jobs\SendSettlementNotification;
use App\Modules\Settlement\Presentation\Livewire\SettlementCenter;
use Carbon\CarbonImmutable;
use Database\Seeders\PhaseTwoReferenceDataSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;
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
        config([
            'queue.default' => 'sync',
            'dingtalk.enabled' => false,
            'services.settlement_exchange_rate.enabled' => false,
        ]);
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

    public function test_period_history_rebuilds_real_boundaries_across_configuration_changes(): void
    {
        $calculator = app(SettlementPeriodCalculator::class);
        $calculator->saveConfiguration(15, '10:30', $this->admin->id, CarbonImmutable::now());

        $periods = $calculator->recentClosedPeriods(CarbonImmutable::parse('2026-09-20 12:00:00'), 4);

        $this->assertSame(['2026-08-15', '2026-08-01', '2026-07-01', '2026-06-01'], array_map(
            static fn ($period): string => $period->start->toDateString(),
            $periods,
        ));
        $this->assertSame(['2026-09-14', '2026-08-14', '2026-07-31', '2026-06-30'], array_map(
            static fn ($period): string => $period->end->toDateString(),
            $periods,
        ));
    }

    public function test_monthly_run_aggregates_snapshots_and_is_idempotent(): void
    {
        $orderId = $this->createCompletedOrder(10005);
        $manager = app(SettlementRunManager::class);
        $result = $manager->startWithResult('manual', $this->admin->id);
        $run = $result->run;
        $run->refresh();
        $settlement = Settlement::query()->where('settlement_run_id', $run->id)->firstOrFail();

        $this->assertSame('completed', $run->status);
        $this->assertSame(10005, (int) $settlement->total_consumption_krw);
        $this->assertSame(1001, (int) $settlement->total_commission_krw);
        $this->assertDatabaseHas('settlement_items', ['settlement_id' => $settlement->id, 'order_commission_id' => DB::table('order_commissions')->where('order_id', $orderId)->value('id')]);

        $same = app(SettlementRunManager::class)->start('manual', $this->admin->id);
        $this->assertSame($run->id, $same->id);
        $this->assertSame('created_and_completed', $result->outcome);
        $this->assertSame('existing_completed', $manager->startWithResult('manual', $this->admin->id)->outcome);
        $this->assertDatabaseCount('settlement_runs', 1);
        $this->assertDatabaseCount('settlements', 1);
    }

    public function test_historical_run_uses_period_eligibility_instead_of_current_status(): void
    {
        $this->agent->update([
            'cooperation_status' => 'terminated',
            'cooperation_ended_on' => '2026-06-30',
        ]);
        $type = AgentTypeCode::query()->where('code', 'JG')->firstOrFail();
        $notYetJoined = Agent::query()->create([
            'agent_type_code_id' => $type->id,
            'code' => 'FUTURE-JG',
            'name' => '历史周期后加入代理商',
            'cooperation_status' => 'active',
            'cooperation_started_on' => '2026-07-15',
        ]);

        $period = app(SettlementPeriodCalculator::class)->recentClosedPeriods(CarbonImmutable::now(), 2)[1];
        $run = app(SettlementRunManager::class)->startHistorical($period->end->toDateString(), $this->admin->id);

        $this->assertDatabaseHas('settlements', ['settlement_run_id' => $run->id, 'agent_id' => $this->agent->id]);
        $this->assertDatabaseMissing('settlements', ['settlement_run_id' => $run->id, 'agent_id' => $notYetJoined->id]);
    }

    public function test_zero_order_settlement_is_generated_and_can_be_approved(): void
    {
        Storage::fake('local');
        $run = app(SettlementRunManager::class)->start('manual', $this->admin->id);
        $settlement = Settlement::query()->where('settlement_run_id', $run->id)->firstOrFail();

        $this->assertSame('generated', $settlement->generation_status);
        $this->assertSame(0, (int) $settlement->item_count);
        $this->assertDatabaseCount('settlement_items', 0);

        app(SettlementWorkflow::class)->approve($settlement->id, '200', $this->admin->id, '127.0.0.1');

        $this->assertDatabaseHas('settlements', ['id' => $settlement->id, 'status' => 'approved', 'total_commission_krw' => 0]);
    }

    public function test_generation_state_migration_backfills_legacy_rows_and_preserves_rollback(): void
    {
        $orderId = $this->createCompletedOrder(10000);
        $orderCommissionId = (int) DB::table('order_commissions')->where('order_id', $orderId)->value('id');
        $now = now();
        $completedRun = fn (string $start, string $end): SettlementRun => SettlementRun::query()->create([
            'period_start' => $start,
            'period_end' => $end,
            'trigger_source' => 'manual',
            'status' => 'completed',
            'total_agents' => 1,
            'processed_agents' => 1,
            'completed_at' => $now,
            'progress_key' => 'migration-test-'.$start,
        ]);
        $approvedRun = $completedRun('2026-03-01', '2026-03-31');
        $settledRun = $completedRun('2026-04-01', '2026-04-30');
        $zeroOrderRun = $completedRun('2026-05-01', '2026-05-31');

        $schemaMigration = require database_path('migrations/2026_08_04_000100_add_settlement_generation_state.php');
        $backfillMigration = require database_path('migrations/2026_08_04_000200_backfill_settlement_generation_state.php');
        $schemaMigration->down();

        $insertSettlement = function (string $start, string $end, string $status, ?string $snapshot = null, ?string $runId = null) use ($now): int {
            return (int) DB::table('settlements')->insertGetId([
                'agent_id' => $this->agent->id,
                'settlement_run_id' => $runId,
                'period_start' => $start,
                'period_end' => $end,
                'status' => $status,
                'snapshot' => $snapshot,
                'total_consumption_krw' => 10000,
                'total_commission_krw' => 1000,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        };

        $pendingWithItems = $insertSettlement('2026-01-01', '2026-01-31', 'pending_review');
        $rejectedWithItems = $insertSettlement('2026-02-01', '2026-02-28', 'rejected');
        $approved = $insertSettlement('2026-03-01', '2026-03-31', 'approved', null, $approvedRun->id);
        $settled = $insertSettlement('2026-04-01', '2026-04-30', 'settled', null, $settledRun->id);
        $zeroOrder = $insertSettlement(
            '2026-05-01',
            '2026-05-31',
            'pending_review',
            json_encode([
                'source' => 'phase_five_generation',
                'generated_at' => '2026-05-10T12:00:00+08:00',
            ], JSON_THROW_ON_ERROR),
            $zeroOrderRun->id,
        );
        $historical = $insertSettlement(
            '2026-06-01',
            '2026-06-30',
            'paid',
            json_encode(['source' => 'demo_data'], JSON_THROW_ON_ERROR),
        );
        $unverified = $insertSettlement('2026-07-01', '2026-07-31', 'pending_review');

        foreach ([$pendingWithItems, $rejectedWithItems, $historical] as $settlementId) {
            DB::table('settlement_items')->insert([
                'settlement_id' => $settlementId,
                'order_commission_id' => $orderCommissionId,
                'consumption_krw' => 10000,
                'commission_krw' => 1000,
                'rule_snapshot' => json_encode(['rate_bps' => 1000], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $schemaMigration->up();
        $backfillMigration->up();

        $this->assertDatabaseHas('settlements', ['id' => $pendingWithItems, 'generation_status' => 'generated', 'item_count' => 1]);
        $this->assertDatabaseHas('settlements', ['id' => $rejectedWithItems, 'generation_status' => 'generated', 'item_count' => 1]);
        $this->assertNotNull(DB::table('settlements')->where('id', $approved)->value('generated_at'));
        $this->assertNotNull(DB::table('settlements')->where('id', $settled)->value('generated_at'));
        $this->assertDatabaseHas('settlements', ['id' => $zeroOrder, 'generation_status' => 'generated', 'item_count' => 0]);
        $this->assertSame('2026-05-10 12:00:00', DB::table('settlements')->where('id', $zeroOrder)->value('generated_at'));
        $this->assertDatabaseHas('settlements', ['id' => $historical, 'generation_status' => 'not_applicable', 'item_count' => 1]);
        $this->assertDatabaseHas('settlements', ['id' => $unverified, 'generation_status' => 'unverified', 'item_count' => 0]);

        $schemaMigration->down();
        $this->assertFalse(Schema::hasColumn('settlements', 'generation_status'));
        $schemaMigration->up();
        $backfillMigration->up();
        $this->assertTrue(Schema::hasColumn('settlements', 'generation_status'));
        $this->assertDatabaseHas('settlements', ['id' => $unverified, 'generation_status' => 'unverified']);
    }

    public function test_unverified_settlement_can_be_audited_as_historical_and_rejects_normal_users(): void
    {
        $settlement = Settlement::query()->create([
            'agent_id' => $this->agent->id,
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'status' => 'pending_review',
            'generation_status' => 'unverified',
        ]);
        $workflow = app(SettlementWorkflow::class);
        $workflow->recoverUnverifiedAsHistorical($settlement->id, '核对历史导入台账，确认该记录不属于系统生成批次。', $this->admin->id, '127.0.0.1');

        $this->assertDatabaseHas('settlements', [
            'id' => $settlement->id,
            'generation_status' => 'not_applicable',
            'status' => 'pending_review',
        ]);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'settlement',
            'subject_id' => $settlement->id,
            'event' => 'generation_recovered',
        ]);

        $blocked = Settlement::query()->create([
            'agent_id' => $this->agent->id,
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'status' => 'pending_review',
            'generation_status' => 'unverified',
        ]);
        $this->expectException(DomainException::class);
        $workflow->recoverUnverifiedAsHistorical($blocked->id, '普通用户不应执行该操作。', $this->user->id, '127.0.0.1');
    }

    public function test_unverified_settlement_can_create_a_recovery_run_and_regenerate(): void
    {
        $this->createCompletedOrder(10000);
        $settlement = Settlement::query()->create([
            'agent_id' => $this->agent->id,
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'status' => 'pending_review',
            'generation_status' => 'unverified',
            'total_consumption_krw' => 0,
            'total_commission_krw' => 0,
        ]);

        app(SettlementWorkflow::class)->recoverUnverifiedWithBatch(
            $settlement->id,
            '核对订单完成记录和代理商合作期间，确认需要按系统规则恢复生成。',
            $this->admin->id,
            '127.0.0.1',
        );

        $settlement->refresh();
        $run = SettlementRun::query()->findOrFail($settlement->settlement_run_id);
        $this->assertSame('recovery', $run->trigger_source);
        $this->assertSame('completed', $run->status);
        $this->assertSame('generated', $settlement->generation_status);
        $this->assertSame(1, (int) $settlement->item_count);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'settlement',
            'subject_id' => $settlement->id,
            'event' => 'generation_recovered',
        ]);
    }

    public function test_not_applicable_settlements_cannot_be_regenerated_or_show_regeneration_actions(): void
    {
        $runs = [];
        $settlements = [];
        foreach ([['2026-06-01', '2026-06-30', 'pending_review'], ['2026-07-01', '2026-07-31', 'rejected']] as [$start, $end, $status]) {
            $run = SettlementRun::query()->create([
                'period_start' => $start,
                'period_end' => $end,
                'trigger_source' => 'historical',
                'status' => 'running',
                'total_agents' => 1,
                'progress_key' => 'not-applicable-'.$start,
            ]);
            $settlement = Settlement::query()->create([
                'settlement_run_id' => $run->id,
                'agent_id' => $this->agent->id,
                'period_start' => $start,
                'period_end' => $end,
                'status' => $status,
                'generation_status' => 'not_applicable',
                'snapshot' => ['source' => 'historical_import'],
            ]);
            $runs[] = $run;
            $settlements[] = $settlement;
        }

        foreach ($settlements as $index => $settlement) {
            app(SettlementGenerator::class)->generate($runs[$index]->id, $this->agent->id);
            $settlement->refresh();
            $this->assertSame('not_applicable', $settlement->generation_status);
            $this->assertSame('historical_import', data_get($settlement->snapshot, 'source'));
            $this->actingAs($this->admin)->get(route('settlements.show', $settlement))
                ->assertOk()
                ->assertSee('历史月结，仅供查看')
                ->assertDontSee('wire:click="regenerateSettlement"', false);
        }
    }

    public function test_historical_period_can_be_selected_and_generated_without_duplicate_run(): void
    {
        $calculator = app(SettlementPeriodCalculator::class);
        $periods = $calculator->recentClosedPeriods(CarbonImmutable::now(), 3);
        $historical = $periods[1];

        $manager = app(SettlementRunManager::class);
        $run = $manager->startHistorical($historical->end->toDateString(), $this->admin->id);
        $same = $manager->startHistorical($historical->end->toDateString(), $this->admin->id);

        $this->assertSame($run->id, $same->id);
        $this->assertSame('historical', $run->trigger_source);
        $this->assertSame($historical->start->toDateString(), $run->period_start->toDateString());
        $this->assertSame($historical->end->toDateString(), $run->period_end->toDateString());
        $this->assertDatabaseCount('settlement_runs', 1);
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
        $pdf = $documents->firstWhere('format', 'pdf');
        $this->assertNotNull($pdf);
        $this->assertStringStartsWith('%PDF-', Storage::disk('local')->get($pdf->path));
        $this->assertNotEmpty(File::glob(storage_path('framework/cache/dompdf/fonts/gn_cjk_*.ufm')));
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

    public function test_detail_prefills_a_configured_quote_and_records_manual_override_at_six_decimals(): void
    {
        Storage::fake('local');
        config([
            'services.settlement_exchange_rate.enabled' => true,
            'services.settlement_exchange_rate.provider' => 'api_hz',
            'services.settlement_exchange_rate.url' => 'https://quotes.test/api/jinrong/huilv.php',
            'services.settlement_exchange_rate.id' => 'test-id',
            'services.settlement_exchange_rate.key' => 'test-key',
        ]);
        Http::fake(['https://quotes.test/*' => Http::response([
            'code' => 200,
            'rate' => '200.1234567',
            'uptime' => '2026-08-03 09:00:00',
        ])]);
        $this->createCompletedOrder(10000);
        $settlement = Settlement::query()->where('settlement_run_id', app(SettlementRunManager::class)->start('manual', $this->admin->id)->id)->firstOrFail();

        $this->actingAs($this->admin)->get(route('settlements.show', $settlement))->assertOk()->assertSee('已自动填入');
        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://quotes.test/api/jinrong/huilv.php')
            && $request['id'] === 'test-id'
            && $request['key'] === 'test-key'
            && $request['from'] === 'CNY'
            && $request['to'] === 'KRW'
            && $request['money'] === '1');
        $settlement->refresh();
        $this->assertSame('200.123457', (string) $settlement->exchange_rate_krw_per_cny);
        $this->assertSame('available', $settlement->exchange_rate_quote_status);
        $this->assertSame('api_hz', $settlement->exchange_rate_quote_source);

        app(SettlementWorkflow::class)->approve($settlement->id, '201.9876547', $this->admin->id, '127.0.0.1');
        $this->assertDatabaseHas('settlements', [
            'id' => $settlement->id,
            'exchange_rate_krw_per_cny' => '201.987655',
            'exchange_rate_manual_override' => true,
        ]);
        $properties = DB::table('activity_log')->where('subject_id', $settlement->id)->where('event', 'approved')->value('properties');
        $properties = is_string($properties) ? json_decode($properties, true, flags: JSON_THROW_ON_ERROR) : $properties;
        $this->assertSame('201.987655', $properties['exchange_rate_krw_per_cny']);
        $this->assertSame('api_hz', $properties['exchange_rate_quote_source']);
        $this->assertNotNull($properties['exchange_rate_quoted_at']);
        $this->assertTrue($properties['exchange_rate_manual_override']);
    }

    public function test_quote_failure_keeps_manual_review_available_and_status_correction_is_audited(): void
    {
        Storage::fake('local');
        config([
            'services.settlement_exchange_rate.enabled' => true,
            'services.settlement_exchange_rate.provider' => 'api_hz',
            'services.settlement_exchange_rate.url' => 'https://quotes-failure.test/api/jinrong/huilv.php',
            'services.settlement_exchange_rate.id' => 'test-id',
            'services.settlement_exchange_rate.key' => 'test-key',
        ]);
        Http::fake(['https://quotes-failure.test/*' => Http::response([], 503)]);
        $this->createCompletedOrder(10000);
        $settlement = Settlement::query()->where('settlement_run_id', app(SettlementRunManager::class)->start('manual', $this->admin->id)->id)->firstOrFail();

        $this->actingAs($this->admin)->get(route('settlements.show', $settlement))->assertOk()->assertSee('最新报价不可用');
        $settlement->refresh();
        $this->assertSame('unavailable', $settlement->exchange_rate_quote_status);
        app(SettlementWorkflow::class)->approve($settlement->id, '200', $this->admin->id, '127.0.0.1');
        app(SettlementWorkflow::class)->settle($settlement->id, $this->admin->id, '127.0.0.1');
        app(SettlementWorkflow::class)->correctStatus($settlement->id, 'approved', '外部付款尚未确认，撤回结清。', $this->admin->id, '127.0.0.1');

        $this->assertDatabaseHas('settlements', ['id' => $settlement->id, 'status' => 'approved', 'settled_on' => null, 'settled_by' => null, 'confirmed_at' => null]);
        app(SettlementWorkflow::class)->correctStatus($settlement->id, 'pending_review', '需要重新核对汇率和结算明细。', $this->admin->id, '127.0.0.1');
        $this->assertDatabaseHas('settlements', [
            'id' => $settlement->id,
            'status' => 'pending_review',
            'reviewed_by' => null,
            'reviewed_at' => null,
            'payout_amount_cny_fen' => 0,
            'settled_on' => null,
            'settled_by' => null,
            'confirmed_at' => null,
        ]);
        $this->assertDatabaseMissing('settlement_items', ['settlement_id' => $settlement->id]);
        $this->assertDatabaseMissing('settlement_documents', ['settlement_id' => $settlement->id]);
        $settlement->refresh();
        $this->assertSame('200.000000', (string) $settlement->exchange_rate_krw_per_cny);
        $this->assertSame(0, $settlement->total_consumption_krw);
        $this->assertSame(0, $settlement->total_commission_krw);
        app(SettlementGenerator::class)->generate((string) $settlement->settlement_run_id, $this->agent->id);
        $settlement->refresh();
        $this->assertGreaterThan(0, $settlement->total_consumption_krw);
        $this->assertGreaterThan(0, $settlement->total_commission_krw);
        $this->assertDatabaseHas('settlement_items', ['settlement_id' => $settlement->id]);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'settlement', 'subject_id' => $settlement->id, 'event' => 'status_corrected']);
    }

    public function test_quote_failure_with_existing_rate_is_marked_without_success_feedback(): void
    {
        config([
            'services.settlement_exchange_rate.enabled' => true,
            'services.settlement_exchange_rate.provider' => 'api_hz',
            'services.settlement_exchange_rate.url' => 'https://quotes-retained.test/api/jinrong/huilv.php',
            'services.settlement_exchange_rate.id' => 'test-id',
            'services.settlement_exchange_rate.key' => 'test-key',
        ]);
        Http::fake(['https://quotes-retained.test/*' => Http::response([], 503)]);
        $settlement = Settlement::query()->create([
            'agent_id' => $this->agent->id,
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'status' => 'pending_review',
            'generation_status' => 'generated',
            'exchange_rate_krw_per_cny' => '200.000000',
            'exchange_rate_quote_status' => 'available',
            'exchange_rate_quote_source' => 'api_hz',
            'exchange_rate_quoted_at' => '2026-07-31 09:00:00',
        ]);

        app(ExchangeRateQuoteService::class)->refreshFor($settlement, true);
        $settlement->refresh();

        $this->assertSame('failed_retained_old_rate', $settlement->exchange_rate_quote_status);
        $this->assertSame('200.000000', (string) $settlement->exchange_rate_krw_per_cny);
        $this->assertNotNull($settlement->exchange_rate_quote_attempted_at);
        $this->assertNotNull($settlement->exchange_rate_quote_error);
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

    public function test_korean_admin_sees_localized_settlement_pages(): void
    {
        $admin = User::factory()->superAdmin()->withTwoFactor()->create(['preferred_locale' => 'ko_KR']);

        $this->actingAs($admin)->get(route('settlements.index'))
            ->assertOk()
            ->assertSee('<html lang="ko-KR"', false)
            ->assertSee('월말 정산 센터')
            ->assertSee('최신 정산 생성')
            ->assertDontSee('月结中心');
    }

    public function test_settlement_notification_job_carries_initiator_locale(): void
    {
        $this->admin->update(['preferred_locale' => 'ko_KR']);
        $run = SettlementRun::query()->create([
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'trigger_source' => 'manual',
            'status' => 'completed',
            'total_agents' => 0,
            'processed_agents' => 0,
            'notification_status' => 'pending',
            'initiated_by' => $this->admin->id,
        ]);
        Queue::fake();

        $this->assertSame(1, app(SettlementNotificationDispatcher::class)->dispatchCompleted());
        Queue::assertPushed(
            SendSettlementNotification::class,
            fn (SendSettlementNotification $job): bool => $job->locale === 'ko_KR',
        );
    }

    public function test_settlement_business_errors_follow_the_current_locale(): void
    {
        $previousLocale = App::getLocale();
        App::setLocale('ko_KR');

        try {
            $this->expectException(DomainException::class);
            $this->expectExceptionMessage('정산 환율은 유효한 숫자여야 합니다.');
            app(SettlementWorkflow::class)->approve(999999, 'not-a-rate', $this->admin->id, null);
        } finally {
            App::setLocale($previousLocale);
        }
    }

    public function test_settlement_detail_navigates_only_within_the_same_run_in_stable_order(): void
    {
        $run = SettlementRun::query()->create([
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'trigger_source' => 'manual',
            'status' => 'completed',
            'total_agents' => 3,
            'processed_agents' => 3,
        ]);
        $agents = collect(range(1, 3))->map(fn (int $index): Agent => $this->createSettlementAgent($index))->all();
        $settlements = collect($agents)->map(fn (Agent $agent): Settlement => Settlement::query()->create([
            'settlement_run_id' => $run->id,
            'agent_id' => $agent->id,
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'status' => 'pending_review',
            'generation_status' => 'generated',
            'snapshot' => ['agent' => ['code' => $agent->code, 'name' => $agent->name]],
        ]))->all();

        $this->actingAs($this->admin)->get(route('settlements.show', $settlements[1]))
            ->assertOk()
            ->assertSee('← 上一条')
            ->assertSee('下一条 →')
            ->assertSee('href="'.route('settlements.show', $settlements[0]->id).'"', false)
            ->assertSee('href="'.route('settlements.show', $settlements[2]->id).'"', false);
        $this->actingAs($this->admin)->get(route('settlements.show', $settlements[0]))
            ->assertOk()
            ->assertSee('← 上一条</span>', false)
            ->assertSee('href="'.route('settlements.show', $settlements[1]->id).'"', false);
        $this->actingAs($this->admin)->get(route('settlements.show', $settlements[2]))
            ->assertOk()
            ->assertSee('下一条 →</span>', false)
            ->assertSee('href="'.route('settlements.show', $settlements[1]->id).'"', false);
    }

    public function test_settlement_center_preloads_and_preserves_collapsed_batch_state(): void
    {
        $run = SettlementRun::query()->create([
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'trigger_source' => 'manual',
            'status' => 'completed',
            'total_agents' => 1,
            'processed_agents' => 1,
        ]);
        Settlement::query()->create([
            'settlement_run_id' => $run->id,
            'agent_id' => $this->agent->id,
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'status' => 'pending_review',
            'generation_status' => 'generated',
            'snapshot' => ['agent' => ['name' => '可折叠代理商']],
        ]);

        $this->actingAs($this->admin);
        $component = Livewire::test(SettlementCenter::class);
        $component->assertSee('可折叠代理商')
            ->call('toggleRun', $run->id)
            ->assertDontSee('可折叠代理商')
            ->call('toggleRun', $run->id)
            ->assertSee('可折叠代理商');
    }

    public function test_failed_run_detail_and_xlsx_report_expose_only_current_failures(): void
    {
        Storage::fake('local');
        $run = SettlementRun::query()->create([
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'trigger_source' => 'manual',
            'status' => 'partial_failed',
            'total_agents' => 1,
            'failed_agents' => 1,
            'errors' => [(string) $this->agent->id => '已完成订单 181 缺少推广费快照。'],
        ]);

        $this->actingAs($this->user)->get(route('settlements.runs.failures', $run))->assertForbidden();
        $this->actingAs($this->admin)->get(route('settlements.runs.failures', $run))
            ->assertOk()
            ->assertSee($this->agent->code)
            ->assertSee($this->agent->name)
            ->assertSee('已完成订单 181 缺少推广费快照。')
            ->assertSee('下载失败报告');

        $failures = app(SettlementRunFailureReader::class)->read($run);
        $this->assertCount(1, $failures);
        $this->assertSame($this->agent->code, $failures[0]->agentCode);
        $generator = app(SettlementRunFailureReportGenerator::class);
        $path = $generator->generate($run);
        $secondPath = $generator->generate($run);
        $this->assertNotSame($path, $secondPath);
        Storage::disk('local')->assertExists($path);
        Storage::disk('local')->assertExists($secondPath);

        $spreadsheet = IOFactory::load(Storage::disk('local')->path($path));
        $sheet = $spreadsheet->getActiveSheet();
        $this->assertSame('批次编号', $sheet->getCell('A1')->getValue());
        $this->assertSame('代理商编号', $sheet->getCell('C1')->getValue());
        $this->assertSame($this->agent->code, $sheet->getCell('C2')->getValue());
        $this->assertSame('已完成订单 181 缺少推广费快照。', $sheet->getCell('F2')->getValue());
        $spreadsheet->disconnectWorksheets();

        $this->actingAs($this->admin)->get(route('settlements.runs.failures.download', $run))
            ->assertOk()
            ->assertHeader('content-disposition');

        $run->update(['failed_agents' => 0, 'errors' => null]);
        $this->actingAs($this->admin)->get(route('settlements.runs.failures', $run->fresh()))
            ->assertOk()
            ->assertSee('当前没有未解决的失败项')
            ->assertDontSee('下载失败报告');
        $this->actingAs($this->admin)->get(route('settlements.runs.failures.download', $run->fresh()))
            ->assertNotFound();
    }

    public function test_failed_run_diagnostics_do_not_require_monthly_agent_context(): void
    {
        $agentWithoutGrade = $this->createSettlementAgent(99);
        $run = SettlementRun::query()->create([
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'trigger_source' => 'manual',
            'status' => 'partial_failed',
            'total_agents' => 1,
            'failed_agents' => 1,
            'errors' => [(string) $agentWithoutGrade->id => '代理商在当月没有生效政策等级。'],
        ]);

        $this->actingAs($this->admin)->get(route('settlements.runs.failures', $run))
            ->assertOk()
            ->assertSee($agentWithoutGrade->code)
            ->assertSee('代理商在当月没有生效政策等级。');
    }

    public function test_failed_run_diagnostics_keep_original_id_when_agent_is_missing(): void
    {
        Storage::fake('local');
        $missingAgentId = 987654321;
        $run = SettlementRun::query()->create([
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'trigger_source' => 'manual',
            'status' => 'partial_failed',
            'total_agents' => 1,
            'failed_agents' => 1,
            'errors' => [(string) $missingAgentId => '代理商已被删除。'],
        ]);

        $this->actingAs($this->admin)->get(route('settlements.runs.failures', $run))
            ->assertOk()
            ->assertSee('未知')
            ->assertSee('代理商不存在或已删除')
            ->assertSee((string) $missingAgentId);

        $path = app(SettlementRunFailureReportGenerator::class)->generate($run);
        Storage::disk('local')->assertExists($path);
    }

    public function test_failure_report_keeps_formula_like_values_as_strings(): void
    {
        Storage::fake('local');
        $this->agent->update(['name' => '=1+1']);
        $run = SettlementRun::query()->create([
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'trigger_source' => 'manual',
            'status' => 'partial_failed',
            'total_agents' => 1,
            'failed_agents' => 1,
            'errors' => [(string) $this->agent->id => '=HYPERLINK("https://example.com","查看")'],
        ]);

        $path = app(SettlementRunFailureReportGenerator::class)->generate($run);
        $spreadsheet = IOFactory::load(Storage::disk('local')->path($path));
        $sheet = $spreadsheet->getActiveSheet();

        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('D2')->getDataType());
        $this->assertSame('=1+1', $sheet->getCell('D2')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('F2')->getDataType());
        $this->assertSame('=HYPERLINK("https://example.com","查看")', $sheet->getCell('F2')->getValue());
        $spreadsheet->disconnectWorksheets();
    }

    public function test_unexpected_generation_failures_have_unique_searchable_references(): void
    {
        Log::spy();
        $run = SettlementRun::query()->create([
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'trigger_source' => 'manual',
            'status' => 'running',
            'total_agents' => 1,
        ]);
        $generator = app(SettlementGenerator::class);

        $generator->markFailed($run->id, $this->agent->id, new RuntimeException('unexpected failure one'));
        $firstMessage = $run->fresh()->errors[(string) $this->agent->id];
        $generator->markFailed($run->id, $this->agent->id, new RuntimeException('unexpected failure two'));
        $secondMessage = $run->fresh()->errors[(string) $this->agent->id];

        preg_match('/参考编号：(.+?)。$/u', $firstMessage, $firstMatches);
        preg_match('/参考编号：(.+?)。$/u', $secondMessage, $secondMatches);
        $references = [$firstMatches[1] ?? null, $secondMatches[1] ?? null];
        $this->assertNotNull($references[0]);
        $this->assertNotNull($references[1]);
        $this->assertNotSame($references[0], $references[1]);

        $loggedReferences = [];
        Log::shouldHaveReceived('error')->twice()->withArgs(function (string $message, array $context) use (&$loggedReferences, $run): bool {
            if ($message !== 'Settlement generation failed.'
                || ($context['run_id'] ?? null) !== $run->id
                || ($context['agent_id'] ?? null) !== $this->agent->id) {
                return false;
            }
            $loggedReferences[] = $context['reference'] ?? null;

            return true;
        });
        $this->assertEqualsCanonicalizing($references, $loggedReferences);
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

    private function createSettlementAgent(int $index): Agent
    {
        $type = AgentTypeCode::query()->where('code', 'JG')->firstOrFail();

        return Agent::query()->create([
            'agent_type_code_id' => $type->id,
            'code' => 'SETTLE-JG-'.$index,
            'name' => '排序代理商 '.$index,
            'cooperation_status' => 'active',
            'cooperation_started_on' => '2026-01-01',
        ]);
    }
}
