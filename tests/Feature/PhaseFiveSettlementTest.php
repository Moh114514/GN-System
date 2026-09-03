<?php

namespace Tests\Feature;

use App\Infrastructure\Time\BusinessClock;
use App\Models\User;
use App\Modules\Agent\Application\Contracts\SettlementAgentGateway;
use App\Modules\Agent\Application\Services\DatabaseReferenceConfigurationImportGateway;
use App\Modules\Agent\Infrastructure\Models\Agent;
use App\Modules\Agent\Infrastructure\Models\AgentGradeAssignment;
use App\Modules\Agent\Infrastructure\Models\AgentTypeCode;
use App\Modules\Agent\Infrastructure\Models\PolicyGrade;
use App\Modules\Agent\Infrastructure\Models\PolicySystem;
use App\Modules\Config\Infrastructure\Models\Institution;
use App\Modules\Customer\Infrastructure\Models\Customer;
use App\Modules\Order\Application\Contracts\DailyOrderGateway;
use App\Modules\Order\Application\Data\DailyOrderData;
use App\Modules\Settlement\Application\Contracts\CommissionConfigurationGateway;
use App\Modules\Settlement\Application\Data\HistoricalCommissionRuleData;
use App\Modules\Settlement\Application\Services\ExchangeRateQuoteService;
use App\Modules\Settlement\Application\Services\SettlementDocumentGenerator;
use App\Modules\Settlement\Application\Services\SettlementFreshnessChecker;
use App\Modules\Settlement\Application\Services\SettlementGenerator;
use App\Modules\Settlement\Application\Services\SettlementNotificationDispatcher;
use App\Modules\Settlement\Application\Services\SettlementPeriodCalculator;
use App\Modules\Settlement\Application\Services\SettlementRunFailureReader;
use App\Modules\Settlement\Application\Services\SettlementRunFailureReportGenerator;
use App\Modules\Settlement\Application\Services\SettlementRunManager;
use App\Modules\Settlement\Application\Services\SettlementRunReconciler;
use App\Modules\Settlement\Application\Services\SettlementWorkflow;
use App\Modules\Settlement\Infrastructure\Models\CommissionRule;
use App\Modules\Settlement\Infrastructure\Models\Settlement;
use App\Modules\Settlement\Infrastructure\Models\SettlementConfiguration;
use App\Modules\Settlement\Infrastructure\Models\SettlementDocument;
use App\Modules\Settlement\Infrastructure\Models\SettlementRun;
use App\Modules\Settlement\Infrastructure\Models\SettlementRunMember;
use App\Modules\Settlement\Jobs\GenerateAgentSettlement;
use App\Modules\Settlement\Jobs\SendSettlementNotification;
use App\Modules\Settlement\Presentation\Livewire\SettlementCenter;
use App\Modules\Settlement\Presentation\Livewire\SettlementDetail;
use App\Modules\Settlement\Presentation\Livewire\SettlementHistory;
use Carbon\CarbonImmutable;
use Database\Seeders\PhaseTwoReferenceDataSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
        SettlementConfiguration::query()->updateOrCreate(
            ['effective_from' => '2026-09-01'],
            [
                'boundary_day' => 1,
                'generation_day' => 5,
                'trigger_time' => '09:00:00',
                'timezone' => 'Asia/Shanghai',
                'created_by' => null,
            ],
        );
        $this->user = User::factory()->create();
        $this->admin = User::factory()->superAdmin()->withTwoFactor()->create();
        $system = PolicySystem::query()->create(['name' => '月结政策', 'is_active' => true]);
        $this->grade = PolicyGrade::query()->create([
            'policy_system_id' => $system->id,
            'name' => '标准级',
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

    public function test_period_configuration_uses_natural_months_and_changes_next_generation(): void
    {
        $calculator = app(SettlementPeriodCalculator::class);
        $period = $calculator->latestClosedPeriod(CarbonImmutable::now());
        $this->assertSame('2026-07-01', $period->start->toDateString());
        $this->assertSame('2026-07-31', $period->end->toDateString());
        $this->assertSame(5, $period->generationDay);

        $configuration = $calculator->saveConfiguration('10:30', $this->admin->id, CarbonImmutable::now());
        $this->assertSame('2026-08-05', $configuration->effective_from->toDateString());
        $this->assertSame(5, (int) $configuration->generation_day);
        $this->assertSame('09:00', substr((string) $calculator->activeConfiguration(CarbonImmutable::now())->trigger_time, 0, 5));
        $this->assertSame('10:30', substr((string) $calculator->activeConfiguration(CarbonImmutable::parse('2026-08-10'))->trigger_time, 0, 5));
    }

    public function test_settlement_center_uses_business_clock_for_configuration_dates(): void
    {
        $clock = app(BusinessClock::class);
        $clock->set(CarbonImmutable::parse('2026-09-10 09:00:00'));
        SettlementConfiguration::query()->whereDate('effective_from', '2026-09-01')->update([
            'boundary_day' => 15,
            'generation_day' => 5,
            'trigger_time' => '10:30:00',
            'timezone' => 'Asia/Shanghai',
            'created_by' => $this->admin->id,
        ]);

        $component = Livewire::actingAs($this->admin)->test(SettlementCenter::class);

        $component->assertSet('triggerTime', '10:30')
            ->set('triggerTime', '11:00')
            ->call('saveConfiguration');

        $this->assertDatabaseHas('settlement_configurations', [
            'effective_from' => '2026-10-05',
            'trigger_time' => '11:00:00',
        ]);
    }

    public function test_settlement_preview_reuses_formal_amounts_without_writing_settlement_tables(): void
    {
        $this->createCompletedOrder(10000);
        $run = app(SettlementRunManager::class)->start('manual', $this->admin->id);
        $settlement = Settlement::query()->where('settlement_run_id', $run->id)->firstOrFail();
        $counts = [
            'runs' => SettlementRun::query()->count(),
            'settlements' => Settlement::query()->count(),
            'items' => DB::table('settlement_items')->count(),
        ];

        $component = Livewire::actingAs($this->admin)->test(SettlementCenter::class)
            ->call('preview');

        $preview = collect($component->get('previewResults'))->firstWhere('agent_id', $this->agent->id);
        $this->assertNotNull($preview);
        $this->assertSame((int) $settlement->total_consumption_krw, $preview['consumption_krw']);
        $this->assertSame((int) $settlement->total_commission_krw, $preview['commission_krw']);
        $this->assertSame($counts['runs'], SettlementRun::query()->count());
        $this->assertSame($counts['settlements'], Settlement::query()->count());
        $this->assertSame($counts['items'], DB::table('settlement_items')->count());
        $this->assertDatabaseCount('agent_grade_evaluations', 0);
        $this->assertDatabaseCount('settlement_grade_suggestions', 0);
    }

    public function test_settlement_generation_keeps_commission_calculation_separate_from_manual_grade_configuration(): void
    {
        $this->createCompletedOrder(10000);

        $run = app(SettlementRunManager::class)->start('manual', $this->admin->id);
        $settlement = Settlement::query()->where('settlement_run_id', $run->id)->firstOrFail();

        $this->assertSame(1000, (int) $settlement->total_commission_krw);
        $this->assertDatabaseCount('agent_grade_evaluations', 0);
        $this->assertDatabaseCount('settlement_grade_suggestions', 0);
    }

    public function test_period_history_keeps_legacy_boundaries_before_natural_month_transition(): void
    {
        $calculator = app(SettlementPeriodCalculator::class);
        $legacy = $calculator->activeConfiguration(CarbonImmutable::now());
        $legacy->update([
            'boundary_day' => 15,
            'generation_day' => null,
            'trigger_time' => '10:30:00',
        ]);
        SettlementConfiguration::query()->whereDate('effective_from', '2026-09-01')->update([
            'boundary_day' => 15,
            'generation_day' => 5,
            'trigger_time' => '09:00:00',
            'timezone' => 'Asia/Shanghai',
        ]);

        $periods = $calculator->recentClosedPeriods(CarbonImmutable::parse('2026-09-20 12:00:00'), 4);

        $this->assertSame(['2026-08-01', '2026-07-15', '2026-06-15', '2026-05-15'], array_map(
            static fn ($period): string => $period->start->toDateString(),
            $periods,
        ));
        $this->assertSame(['2026-08-31', '2026-08-14', '2026-07-14', '2026-06-14'], array_map(
            static fn ($period): string => $period->end->toDateString(),
            $periods,
        ));
    }

    public function test_scheduler_generates_previous_natural_month_at_or_after_the_fifth(): void
    {
        $manager = app(SettlementRunManager::class);

        $this->assertNull($manager->startIfDue(CarbonImmutable::parse('2026-09-04 23:59:00')));
        $this->assertNull($manager->startIfDue(CarbonImmutable::parse('2026-09-05 08:59:00')));

        $run = $manager->startIfDue(CarbonImmutable::parse('2026-09-05 09:00:00'));

        $this->assertNotNull($run);
        $this->assertSame('2026-08-01', $run->period_start->toDateString());
        $this->assertSame('2026-08-31', $run->period_end->toDateString());
        $this->assertNull($manager->startIfDue(CarbonImmutable::parse('2026-09-05 09:01:00')));
        $this->assertDatabaseCount('settlement_runs', 1);
    }

    public function test_scheduler_compensates_after_the_generation_window_and_advances_each_month(): void
    {
        $manager = app(SettlementRunManager::class);

        $august = $manager->startIfDue(CarbonImmutable::parse('2026-09-11 12:00:00'));
        $september = $manager->startIfDue(CarbonImmutable::parse('2026-10-05 09:00:00'));

        $this->assertNotNull($august);
        $this->assertNotNull($september);
        $this->assertSame('2026-08-01', $august->period_start->toDateString());
        $this->assertSame('2026-09-01', $september->period_start->toDateString());
        $this->assertDatabaseCount('settlement_runs', 2);
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

    public function test_settlement_freshness_detects_new_order_and_refresh_updates_items_run_and_audit(): void
    {
        $firstOrderId = $this->createCompletedOrder(10000);
        $run = app(SettlementRunManager::class)->start('manual', $this->admin->id);
        $settlement = Settlement::query()->where('settlement_run_id', $run->id)->firstOrFail();
        $commissionBefore = DB::table('order_commissions')->where('order_id', $firstOrderId)->first();

        $secondOrderId = $this->createCompletedOrder(20000);
        $freshness = app(SettlementFreshnessChecker::class)->check($settlement->fresh());
        $this->assertSame('stale', $freshness->status);
        $this->assertSame([$secondOrderId], $freshness->addedOrderIds);
        $this->assertSame(2, $freshness->currentItemCount);

        app(SettlementWorkflow::class)->refreshSettlement($settlement->id, '补录本周期遗漏订单', $this->admin->id, '127.0.0.1');

        $settlement->refresh();
        $this->assertSame(2, (int) $settlement->item_count);
        $this->assertSame(30000, (int) $settlement->total_consumption_krw);
        $this->assertSame(3000, (int) $settlement->total_commission_krw);
        $this->assertSame(2, DB::table('settlement_items')->where('settlement_id', $settlement->id)->count());
        $this->assertSame(30000, (int) $run->refresh()->total_consumption_krw);
        $this->assertSame(3000, (int) $run->total_commission_krw);
        $this->assertEquals((array) $commissionBefore, (array) DB::table('order_commissions')->where('order_id', $firstOrderId)->first());
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'settlement',
            'subject_id' => $settlement->id,
            'event' => 'refreshed',
        ]);
        $properties = json_decode((string) DB::table('activity_log')->where('subject_id', $settlement->id)->where('event', 'refreshed')->value('properties'), true);
        $this->assertSame([$secondOrderId], $properties['added_order_ids']);
        $this->assertSame('补录本周期遗漏订单', $properties['reason']);
    }

    public function test_stale_settlement_cannot_be_approved_or_settled(): void
    {
        $this->createCompletedOrder(10000);
        $run = app(SettlementRunManager::class)->start('manual', $this->admin->id);
        $settlement = Settlement::query()->where('settlement_run_id', $run->id)->firstOrFail();
        $this->createCompletedOrder(20000);

        try {
            app(SettlementWorkflow::class)->approve($settlement->id, '180', $this->admin->id, null);
            $this->fail('A stale settlement must not be approved.');
        } catch (DomainException) {
            $this->assertSame('pending_review', $settlement->fresh()->status);
        }

        $settlement->update(['status' => 'approved']);
        try {
            app(SettlementWorkflow::class)->settle($settlement->id, $this->admin->id, null);
            $this->fail('A stale settlement must not be settled.');
        } catch (DomainException) {
            $this->assertSame('approved', $settlement->fresh()->status);
        }
    }

    public function test_refresh_is_not_blocked_by_removed_grade_suggestion_workflow(): void
    {
        $this->createCompletedOrder(10000);
        $run = app(SettlementRunManager::class)->start('manual', $this->admin->id);
        $settlement = Settlement::query()->where('settlement_run_id', $run->id)->firstOrFail();
        $this->createCompletedOrder(20000);

        app(SettlementWorkflow::class)->refreshSettlement($settlement->id, '补录订单', $this->admin->id, null);
        $this->assertSame(3000, (int) $settlement->fresh()->total_commission_krw);
    }

    public function test_settlement_refresh_only_allows_pending_review_or_rejected(): void
    {
        $this->createCompletedOrder(10000);
        $run = app(SettlementRunManager::class)->start('manual', $this->admin->id);
        $settlement = Settlement::query()->where('settlement_run_id', $run->id)->firstOrFail();
        $workflow = app(SettlementWorkflow::class);

        foreach (['approved', 'settled', 'paid', 'reconciled'] as $status) {
            $settlement->update(['status' => $status]);
            $caught = null;
            try {
                $workflow->refreshSettlement($settlement->id, '不应允许', $this->admin->id, null);
            } catch (DomainException $exception) {
                $caught = $exception;
            }
            $this->assertInstanceOf(DomainException::class, $caught, $status.' should not be refreshable');
        }
    }

    public function test_existing_settlement_is_counted_without_dispatching_a_generation_job(): void
    {
        Settlement::query()->create([
            'agent_id' => $this->agent->id,
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'status' => 'paid',
            'generation_status' => 'not_applicable',
            'snapshot' => ['source' => 'historical_import', 'agent' => ['name' => $this->agent->name]],
        ]);
        Bus::fake();

        $run = app(SettlementRunManager::class)->start('manual', $this->admin->id);
        $run->refresh();

        $this->assertSame('completed', $run->status);
        $this->assertSame(1, $run->total_agents);
        $this->assertSame(0, $run->processed_agents);
        $this->assertSame(1, $run->existing_agents);
        $this->assertSame(0, $run->failed_agents);
        $this->assertNull($run->queue_batch_id);
        Bus::assertNothingBatched();
    }

    public function test_archive_uses_member_settlements_and_only_appears_for_real_documents(): void
    {
        Storage::fake('local');
        $run = SettlementRun::query()->create([
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'trigger_source' => 'historical',
            'status' => 'completed',
            'total_agents' => 2,
            'existing_agents' => 2,
        ]);
        $historical = Settlement::query()->create([
            'agent_id' => $this->agent->id,
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'status' => 'paid',
            'generation_status' => 'not_applicable',
            'snapshot' => ['source' => 'historical_import'],
        ]);
        $generatedAgent = $this->createSettlementAgent(1);
        $generated = Settlement::query()->create([
            'agent_id' => $generatedAgent->id,
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'status' => 'settled',
            'generation_status' => 'generated',
            'snapshot' => ['agent' => ['name' => $generatedAgent->name]],
        ]);
        SettlementRunMember::query()->create(['settlement_run_id' => $run->id, 'agent_id' => $this->agent->id, 'settlement_id' => $historical->id, 'outcome' => 'existing', 'processed_at' => now()]);
        SettlementRunMember::query()->create(['settlement_run_id' => $run->id, 'agent_id' => $generatedAgent->id, 'settlement_id' => $generated->id, 'outcome' => 'existing', 'processed_at' => now()]);

        Livewire::actingAs($this->admin)->test(SettlementCenter::class)->assertDontSee(route('settlements.archive', $run->id), false);

        foreach ([$historical, $generated] as $settlement) {
            $path = "settlements/{$settlement->id}/settlement-{$settlement->id}.pdf";
            Storage::disk('local')->put($path, 'pdf');
            SettlementDocument::query()->create([
                'settlement_id' => $settlement->id,
                'format' => 'pdf',
                'path' => $path,
                'sha256' => hash('sha256', 'pdf'),
                'content_snapshot' => [],
                'generated_at' => now(),
            ]);
        }

        Livewire::actingAs($this->admin)->test(SettlementCenter::class)->assertSee(__('settlements.center.download_documents', ['count' => 2]));
        $path = app(SettlementDocumentGenerator::class)->archiveRun($run->id);
        $archive = new \ZipArchive;
        $this->assertTrue($archive->open(Storage::disk('local')->path($path)));
        $this->assertSame('settlement-'.$historical->id.'.pdf', $archive->getNameIndex(0));
        $this->assertSame('settlement-'.$generated->id.'.pdf', $archive->getNameIndex(1));
        $archive->close();
    }

    public function test_settlement_center_exposes_agent_document_downloads_and_generates_missing_documents(): void
    {
        Storage::fake('local');
        $run = SettlementRun::query()->create([
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'trigger_source' => 'historical',
            'status' => 'completed',
            'total_agents' => 1,
            'existing_agents' => 1,
        ]);
        $settlement = Settlement::query()->create([
            'agent_id' => $this->agent->id,
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'status' => 'paid',
            'generation_status' => 'not_applicable',
            'snapshot' => ['source' => 'historical_import', 'agent' => ['code' => $this->agent->code, 'name' => $this->agent->name]],
        ]);
        SettlementRunMember::query()->create([
            'settlement_run_id' => $run->id,
            'agent_id' => $this->agent->id,
            'settlement_id' => $settlement->id,
            'outcome' => 'existing',
            'processed_at' => now(),
        ]);

        $component = Livewire::actingAs($this->admin)->test(SettlementCenter::class);
        $component->assertSee(__('settlements.detail.documents_regenerate'))
            ->call('regenerateDocuments', $settlement->id);

        $documents = SettlementDocument::query()->where('settlement_id', $settlement->id)->pluck('id', 'format');
        $this->assertCount(3, $documents);
        $component->assertSee(route('settlements.documents.download', $documents['pdf']), false)
            ->assertSee(route('settlements.documents.download', $documents['docx']), false)
            ->assertDontSee(__('settlements.detail.documents_regenerate'));
    }

    public function test_old_job_payload_can_resolve_a_run_and_agent_without_member_id(): void
    {
        $this->createCompletedOrder(10005);
        $run = SettlementRun::query()->create([
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'trigger_source' => 'manual',
            'status' => 'queued',
            'total_agents' => 1,
        ]);

        $job = new GenerateAgentSettlement(memberId: null, runId: $run->id, agentId: $this->agent->id);
        unset($job->memberId);
        /** @var GenerateAgentSettlement $restoredJob */
        $restoredJob = unserialize(serialize($job), ['allowed_classes' => [GenerateAgentSettlement::class]]);
        $restoredJob->handle(app(SettlementGenerator::class));

        $this->assertDatabaseHas('settlement_run_members', [
            'settlement_run_id' => $run->id,
            'agent_id' => $this->agent->id,
            'outcome' => 'generated',
        ]);
    }

    public function test_member_backfill_recalculates_legacy_run_totals_from_members(): void
    {
        $other = $this->createSettlementAgent(1);
        $run = SettlementRun::query()->create([
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'trigger_source' => 'historical',
            'status' => 'completed',
            'total_agents' => 99,
            'processed_agents' => 99,
            'existing_agents' => 0,
            'failed_agents' => 4,
            'total_consumption_krw' => 1,
            'total_commission_krw' => 1,
            'existing_agent_ids' => [$other->id],
        ]);
        Settlement::query()->create([
            'settlement_run_id' => $run->id,
            'agent_id' => $this->agent->id,
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'status' => 'paid',
            'generation_status' => 'generated',
            'total_consumption_krw' => 100,
            'total_commission_krw' => 10,
        ]);
        Settlement::query()->create([
            'agent_id' => $other->id,
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'status' => 'paid',
            'generation_status' => 'not_applicable',
            'total_consumption_krw' => 200,
            'total_commission_krw' => 20,
        ]);

        $migration = require base_path('database/migrations/2026_08_10_000400_backfill_settlement_run_members.php');
        $migration->up();
        $projectionMigration = require base_path('database/migrations/2026_08_11_000100_rebuild_settlement_run_projections.php');
        $projectionMigration->up();

        $run->refresh();
        $this->assertSame(2, $run->total_agents);
        $this->assertSame(1, $run->processed_agents);
        $this->assertSame(1, $run->existing_agents);
        $this->assertSame(0, $run->failed_agents);
        $this->assertSame(300, $run->total_consumption_krw);
        $this->assertSame(30, $run->total_commission_krw);
        $this->assertSame([$other->id], $run->existing_agent_ids);
        $this->assertSame('completed', $run->status);
        $this->assertNotNull($run->completed_at);
    }

    public function test_projection_migration_repairs_pending_and_failed_run_statuses(): void
    {
        $failedAgent = $this->createSettlementAgent(1);
        $pendingRun = SettlementRun::query()->create([
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'trigger_source' => 'historical',
            'status' => 'completed',
            'completed_at' => now(),
        ]);
        $failedRun = SettlementRun::query()->create([
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
            'trigger_source' => 'historical',
            'status' => 'running',
        ]);
        SettlementRunMember::query()->create([
            'settlement_run_id' => $pendingRun->id,
            'agent_id' => $this->agent->id,
            'outcome' => 'pending',
        ]);
        SettlementRunMember::query()->create([
            'settlement_run_id' => $failedRun->id,
            'agent_id' => $failedAgent->id,
            'outcome' => 'failed',
            'error_message_key' => 'settlements.failure_reasons.legacy_unknown',
        ]);

        $migration = require base_path('database/migrations/2026_08_11_000100_rebuild_settlement_run_projections.php');
        $migration->up();

        $this->assertSame('running', $pendingRun->fresh()->status);
        $this->assertNull($pendingRun->fresh()->completed_at);
        $this->assertSame('partial_failed', $failedRun->fresh()->status);
        $this->assertNotNull($failedRun->fresh()->completed_at);
    }

    public function test_projection_migration_preserves_legacy_runs_with_unmaterialized_jobs(): void
    {
        $run = SettlementRun::query()->create([
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'trigger_source' => 'manual',
            'status' => 'running',
            'total_agents' => 2,
            'processed_agents' => 1,
            'completed_at' => null,
        ]);
        $settlement = Settlement::query()->create([
            'settlement_run_id' => $run->id,
            'agent_id' => $this->agent->id,
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'status' => 'pending_review',
            'generation_status' => 'generated',
        ]);
        SettlementRunMember::query()->create([
            'settlement_run_id' => $run->id,
            'agent_id' => $this->agent->id,
            'settlement_id' => $settlement->id,
            'outcome' => 'generated',
        ]);

        $migration = require base_path('database/migrations/2026_08_11_000100_rebuild_settlement_run_projections.php');
        $migration->up();

        $run->refresh();
        $this->assertSame(2, $run->total_agents);
        $this->assertSame('running', $run->status);
        $this->assertNull($run->completed_at);
    }

    public function test_generation_batch_only_dispatches_agents_without_existing_settlements(): void
    {
        Settlement::query()->create([
            'agent_id' => $this->agent->id,
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'status' => 'paid',
            'generation_status' => 'not_applicable',
            'snapshot' => ['source' => 'historical_import', 'agent' => ['name' => $this->agent->name]],
        ]);
        $pendingAgent = $this->createSettlementAgent(1);
        AgentGradeAssignment::query()->create([
            'agent_id' => $pendingAgent->id,
            'policy_grade_id' => $this->grade->id,
            'effective_month' => '2026-01-01',
            'approved_by' => $this->admin->id,
            'reason' => '测试',
        ]);
        Bus::fake();

        $run = app(SettlementRunManager::class)->start('manual', $this->admin->id);
        $run->refresh();

        $this->assertSame('running', $run->status);
        $this->assertSame(2, $run->total_agents);
        $this->assertSame(1, $run->existing_agents);
        $this->assertSame(0, $run->processed_agents);
        $this->assertSame(0, $run->failed_agents);
        Bus::assertBatched(static fn ($batch): bool => $batch->jobs->count() === 1
            && $batch->jobs->first()->agentId === $pendingAgent->id);
    }

    public function test_reconciler_marks_pending_run_without_batch_as_stalled(): void
    {
        $run = SettlementRun::query()->create([
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'trigger_source' => 'manual',
            'status' => 'running',
            'started_at' => now(),
        ]);
        SettlementRunMember::query()->create([
            'settlement_run_id' => $run->id,
            'agent_id' => $this->agent->id,
            'outcome' => 'pending',
        ]);

        $result = app(SettlementRunReconciler::class)->reconcile();

        $this->assertSame(1, $result['stalled']);
        $this->assertSame('stalled', $run->fresh()->status);
    }

    public function test_reconciler_recovery_submits_only_pending_members(): void
    {
        Bus::fake();
        $run = SettlementRun::query()->create([
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'trigger_source' => 'manual',
            'status' => 'stalled',
            'started_at' => now(),
        ]);
        SettlementRunMember::query()->create([
            'settlement_run_id' => $run->id,
            'agent_id' => $this->agent->id,
            'outcome' => 'pending',
        ]);

        $recovered = app(SettlementRunManager::class)->redispatchPending($run->id);

        $this->assertSame('running', $recovered->status);
        $this->assertNotNull($recovered->queue_batch_id);
        Bus::assertBatched(static fn ($batch): bool => $batch->jobs->count() === 1
            && $batch->jobs->first() instanceof GenerateAgentSettlement);
    }

    public function test_failed_queue_job_records_failure_without_resolving_generator(): void
    {
        $run = SettlementRun::query()->create([
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'trigger_source' => 'manual',
            'status' => 'running',
        ]);
        $member = SettlementRunMember::query()->create([
            'settlement_run_id' => $run->id,
            'agent_id' => $this->agent->id,
            'outcome' => 'pending',
        ]);
        app()->bind(SettlementGenerator::class, static function (): never {
            throw new RuntimeException('normal settlement dependencies are unavailable');
        });

        $job = new GenerateAgentSettlement(memberId: $member->id, agentId: $this->agent->id);
        $job->failed(new RuntimeException('queue dependency failure'));

        $this->assertSame('failed', $member->fresh()->outcome);
        $this->assertSame('settlements.failure_reasons.unexpected', $member->fresh()->error_message_key);
    }

    public function test_queue_failure_lifecycle_calls_failed_with_only_the_exception(): void
    {
        $run = SettlementRun::query()->create([
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'trigger_source' => 'manual',
            'status' => 'running',
        ]);
        $member = SettlementRunMember::query()->create([
            'settlement_run_id' => $run->id,
            'agent_id' => $this->agent->id,
            'outcome' => 'pending',
        ]);
        app()->bind(SettlementGenerator::class, static function (): never {
            throw new RuntimeException('normal settlement dependencies are unavailable');
        });

        try {
            Queue::push(new GenerateAgentSettlement(memberId: $member->id, agentId: $this->agent->id));
            $this->fail('The queue job should have failed during handle().');
        } catch (RuntimeException $exception) {
            $this->assertSame('normal settlement dependencies are unavailable', $exception->getMessage());
        }

        $this->assertSame('failed', $member->fresh()->outcome);
        $this->assertSame('settlements.failure_reasons.unexpected', $member->fresh()->error_message_key);
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
                ->assertSee('href="'.route('settlements.index').'"', false)
                ->assertDontSee('href="'.route('settlements.history').'"', false)
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
        $this->assertCount(3, $documents);
        $this->assertEquals($documents[0]->content_snapshot, $documents[1]->content_snapshot);
        foreach ($documents as $document) {
            Storage::disk('local')->assertExists($document->path);
        }
        $xlsx = $documents->firstWhere('format', 'xlsx');
        $this->assertNotNull($xlsx);
        $workbook = IOFactory::load(Storage::disk('local')->path($xlsx->path));
        $sheet = $workbook->getActiveSheet();
        $this->assertSame(__('settlements.documents.title'), $sheet->getCell('A1')->getValue());
        $this->assertSame(__('settlements.documents.headers.order'), $sheet->getCell('A9')->getValue());
        $this->assertEquals(10000, $sheet->getCell('D10')->getValue());
        $workbook->disconnectWorksheets();
        $pdf = $documents->firstWhere('format', 'pdf');
        $this->assertNotNull($pdf);
        $this->assertStringStartsWith('%PDF-', Storage::disk('local')->get($pdf->path));
        $this->assertFileIsReadable((string) config('reporting.pdf.font_regular_path'));
        $this->assertFileIsReadable((string) config('reporting.pdf.font_bold_path'));
        $this->assertStringEndsWith('GNSystemSans-Regular.ttf', (string) config('reporting.pdf.font_regular_path'));
        $this->assertStringEndsWith('GNSystemSans-Bold.ttf', (string) config('reporting.pdf.font_bold_path'));
        $this->assertGreaterThan(0, Storage::disk('local')->size($pdf->path));
        $workflow->settle($settlement->id, $this->admin->id, null);
        $this->assertDatabaseHas('settlements', ['id' => $settlement->id, 'status' => 'settled']);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'settlement', 'subject_id' => $settlement->id, 'event' => 'settled']);
        $settlement->refresh();
        $this->assertSame('settled', $settlement->status);
        foreach ($documents as $document) {
            Storage::disk('local')->assertExists($document->path);
            $this->actingAs($this->admin)
                ->get(route('settlements.documents.download', $document->id))
                ->assertOk()
                ->assertHeader('content-disposition');
        }
        $detail = $this->actingAs($this->admin)->get(route('settlements.show', $settlement->id));
        $detail->assertOk();
        foreach ($documents as $document) {
            $detail->assertSee('href="'.route('settlements.documents.download', $document->id).'"', false);
        }
    }

    public function test_historical_paid_and_reconciled_settlements_can_generate_and_download_documents(): void
    {
        Storage::fake('local');
        $orderId = $this->createCompletedOrder(10000);
        $commissionId = DB::table('order_commissions')->where('order_id', $orderId)->value('id');

        foreach ([
            ['paid', '2026-06-01', '2026-06-30'],
            ['reconciled', '2026-05-01', '2026-05-31'],
        ] as [$status, $periodStart, $periodEnd]) {
            $settlement = Settlement::query()->create([
                'agent_id' => $this->agent->id,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'settled_on' => '2026-07-01',
                'exchange_rate_krw_per_cny' => '200',
                'total_consumption_krw' => 10000,
                'total_commission_krw' => 1000,
                'payout_amount_cny_fen' => 500,
                'status' => $status,
                'generation_status' => 'not_applicable',
                'snapshot' => ['source' => 'historical_import', 'agent' => ['code' => $this->agent->code, 'name' => $this->agent->name]],
            ]);
            DB::table('settlement_items')->insert([
                'settlement_id' => $settlement->id,
                'order_commission_id' => $commissionId,
                'consumption_krw' => 10000,
                'commission_krw' => 1000,
                'rule_snapshot' => json_encode([
                    'order' => ['id' => $orderId, 'project_name' => 'Historical document item', 'completed_on' => '2026-06-15'],
                    'rate_bps' => 1000,
                ], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $beforeGeneration = $this->actingAs($this->admin)->get(route('settlements.show', $settlement->id));
            $beforeGeneration->assertOk()->assertSee('wire:click="regenerateDocuments"', false);

            app(SettlementWorkflow::class)->regenerateDocuments($settlement->id);

            $documents = SettlementDocument::query()->where('settlement_id', $settlement->id)->orderBy('format')->get();
            $this->assertCount(3, $documents);
            $this->assertCount(1, data_get($documents->firstWhere('format', 'pdf')->content_snapshot, 'items', []));
            $this->assertSame($status, $settlement->refresh()->status);
            $this->assertSame('not_applicable', $settlement->generation_status);
            foreach ($documents as $document) {
                Storage::disk('local')->assertExists($document->path);
                $this->actingAs($this->admin)
                    ->get(route('settlements.documents.download', $document->id))
                    ->assertOk()
                    ->assertHeader('content-disposition');
            }

            $detail = $this->actingAs($this->admin)->get(route('settlements.show', $settlement->id));
            $detail->assertOk()->assertSee('wire:click="regenerateDocuments"', false);
            foreach ($documents as $document) {
                $detail->assertSee('href="'.route('settlements.documents.download', $document->id).'"', false);
            }
        }
    }

    public function test_historical_document_generation_requires_not_applicable_generation_state(): void
    {
        $settlement = Settlement::query()->create([
            'agent_id' => $this->agent->id,
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-30',
            'status' => 'paid',
            'generation_status' => 'generated',
            'snapshot' => ['source' => 'historical_import'],
        ]);

        $this->expectException(DomainException::class);
        app(SettlementWorkflow::class)->regenerateDocuments($settlement->id);
    }

    public function test_historical_grade_and_rate_correction_does_not_change_settled_snapshot_or_items(): void
    {
        $orderId = $this->createCompletedOrder(10000);
        $run = app(SettlementRunManager::class)->start('manual', $this->admin->id);
        $settlement = Settlement::query()->where('settlement_run_id', $run->id)->firstOrFail();
        app(SettlementWorkflow::class)->approve($settlement->id, '200', $this->admin->id, '127.0.0.1');
        app(SettlementWorkflow::class)->settle($settlement->id, $this->admin->id, null);

        $commissionBefore = DB::table('order_commissions')->where('order_id', $orderId)->first();
        $itemBefore = DB::table('settlement_items')->where('settlement_id', $settlement->id)->first();
        $settlementBefore = $settlement->fresh()->only(['status', 'total_consumption_krw', 'total_commission_krw', 'snapshot']);
        $batchId = (string) Str::uuid();
        $policySystem = PolicySystem::query()->findOrFail($this->grade->policy_system_id);

        app(DatabaseReferenceConfigurationImportGateway::class)->importHistoricalGradeAssignments([[
            'agent_code' => $this->agent->code,
            'policy_system' => $policySystem->name,
            'policy_grade' => $this->grade->name,
            'effective_month' => '2026-04-01',
            'reason' => '历史月等级纠错',
        ]], $this->admin->id, $batchId, null);
        app(CommissionConfigurationGateway::class)->importHistoricalCorrectionRule(new HistoricalCommissionRuleData(
            policyGradeId: $this->grade->id,
            institutionId: $this->institution->id,
            rateBps: 1500,
            effectiveMonth: CarbonImmutable::parse('2026-04-01'),
            isActive: true,
            importBatchId: $batchId,
            reason: '历史月费率纠错',
            actorId: $this->admin->id,
            ipAddress: null,
        ));

        $this->assertEquals($commissionBefore, DB::table('order_commissions')->where('order_id', $orderId)->first());
        $this->assertEquals($itemBefore, DB::table('settlement_items')->where('settlement_id', $settlement->id)->first());
        $this->assertSame($settlementBefore, $settlement->fresh()->only(['status', 'total_consumption_krw', 'total_commission_krw', 'snapshot']));
    }

    public function test_existing_historical_settlement_is_not_overwritten_or_marked_as_failure(): void
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
            'failed_agents' => 1,
            'errors' => [(string) $this->agent->id => ['message_key' => 'settlements.failure_reasons.existing_settlement', 'parameters' => []]],
        ]);
        app(SettlementGenerator::class)->generate($run->id, $this->agent->id);
        app(SettlementGenerator::class)->generate($run->id, $this->agent->id);
        $this->assertSame('paid', $settlement->refresh()->status);
        $this->assertSame('completed', $run->refresh()->status);
        $this->assertSame(1, $run->existing_agents);
        $this->assertSame(0, $run->failed_agents);
        $this->assertNull($run->errors);
        $this->assertSame([$this->agent->id], $run->existing_agent_ids);
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

    public function test_livewire_currency_switch_from_krw_to_cny_refreshes_quote_and_shows_snapshot(): void
    {
        config([
            'services.settlement_exchange_rate.enabled' => true,
            'services.settlement_exchange_rate.provider' => 'api_hz',
            'services.settlement_exchange_rate.url' => 'https://quotes-livewire.test/api/jinrong/huilv.php',
            'services.settlement_exchange_rate.id' => 'test-id',
            'services.settlement_exchange_rate.key' => 'test-key',
        ]);
        Http::fake(['https://quotes-livewire.test/*' => Http::response([
            'code' => 200,
            'rate' => '200.1234567',
            'uptime' => '2026-08-03 09:00:00',
        ])]);
        $settlement = Settlement::query()->create([
            'agent_id' => $this->agent->id,
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'status' => 'pending_review',
            'generation_status' => 'pending',
            'settlement_currency' => 'KRW',
            'total_commission_krw' => 1000,
        ]);

        Livewire::actingAs($this->admin)
            ->test(SettlementDetail::class, ['settlement' => $settlement->id])
            ->set('settlementCurrency', 'CNY')
            ->assertSet('exchangeRate', '200.123457')
            ->assertSee('wire:model.live="settlementCurrency"', false)
            ->assertSee('1 CNY = 200.123457 KRW')
            ->assertSee('api_hz');
        Http::assertSentCount(1);
    }

    public function test_livewire_currency_switch_from_cny_to_krw_clears_rate_and_keeps_approval_action_visible(): void
    {
        $settlement = Settlement::query()->create([
            'agent_id' => $this->agent->id,
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'status' => 'pending_review',
            'generation_status' => 'pending',
            'settlement_currency' => 'CNY',
            'exchange_rate_krw_per_cny' => '200.000000',
            'exchange_rate_quote_status' => 'available',
            'exchange_rate_quote_source' => 'api_hz',
            'exchange_rate_quoted_at' => '2026-07-31 09:00:00',
            'total_commission_krw' => 1000,
        ]);

        Livewire::actingAs($this->admin)
            ->test(SettlementDetail::class, ['settlement' => $settlement->id])
            ->set('settlementCurrency', 'KRW')
            ->assertSet('exchangeRate', '')
            ->assertSee('wire:submit="approve"', false)
            ->assertSee(__('settlements.detail.approve_generate'));
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

    public function test_korean_settlement_center_localizes_run_and_settlement_statuses(): void
    {
        $run = SettlementRun::query()->create([
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'trigger_source' => 'manual',
            'status' => 'completed',
            'total_agents' => 1,
            'processed_agents' => 1,
            'notification_status' => 'sent',
        ]);
        Settlement::query()->create([
            'settlement_run_id' => $run->id,
            'agent_id' => $this->agent->id,
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'status' => 'pending_review',
            'generation_status' => 'generated',
            'snapshot' => ['agent' => ['name' => '월말 테스트 에이전시']],
        ]);

        $previousLocale = App::getLocale();
        App::setLocale('ko_KR');

        try {
            Livewire::actingAs($this->admin)
                ->test(SettlementCenter::class)
                ->assertSee('완료')
                ->assertSee('검토 대기')
                ->assertDontSee('已生成')
                ->assertDontSee('待审核');
        } finally {
            App::setLocale($previousLocale);
        }
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

    public function test_settlement_center_defaults_to_latest_period_and_switches_displayed_batch(): void
    {
        $olderRun = SettlementRun::query()->create([
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'trigger_source' => 'manual',
            'status' => 'completed',
            'total_agents' => 1,
            'processed_agents' => 1,
        ]);
        $latestRun = SettlementRun::query()->create([
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
            'trigger_source' => 'scheduled',
            'status' => 'completed',
            'total_agents' => 1,
            'processed_agents' => 1,
        ]);
        Settlement::query()->create([
            'settlement_run_id' => $olderRun->id,
            'agent_id' => $this->agent->id,
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'status' => 'pending_review',
            'generation_status' => 'generated',
            'snapshot' => ['agent' => ['name' => '七月批次代理商']],
        ]);
        Settlement::query()->create([
            'settlement_run_id' => $latestRun->id,
            'agent_id' => $this->agent->id,
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
            'status' => 'pending_review',
            'generation_status' => 'generated',
            'snapshot' => ['agent' => ['name' => '八月批次代理商']],
        ]);

        $component = Livewire::actingAs($this->admin)->test(SettlementCenter::class);
        $component->assertSet('selectedPeriodEnd', '2026-08-31')
            ->assertSee('八月批次代理商')
            ->assertDontSee('七月批次代理商')
            ->set('selectedPeriodEnd', '2026-07-31')
            ->assertSee('七月批次代理商')
            ->assertDontSee('八月批次代理商');
    }

    public function test_settlement_center_preserves_selected_period_in_detail_back_link(): void
    {
        $olderRun = SettlementRun::query()->create([
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'trigger_source' => 'manual',
            'status' => 'completed',
            'total_agents' => 1,
            'processed_agents' => 1,
        ]);
        $latestRun = SettlementRun::query()->create([
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
            'trigger_source' => 'scheduled',
            'status' => 'completed',
            'total_agents' => 1,
            'processed_agents' => 1,
        ]);
        $olderSettlement = Settlement::query()->create([
            'settlement_run_id' => $olderRun->id,
            'agent_id' => $this->agent->id,
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'status' => 'pending_review',
            'generation_status' => 'generated',
            'snapshot' => ['agent' => ['name' => 'older settlement']],
        ]);
        Settlement::query()->create([
            'settlement_run_id' => $latestRun->id,
            'agent_id' => $this->agent->id,
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
            'status' => 'pending_review',
            'generation_status' => 'generated',
            'snapshot' => ['agent' => ['name' => 'latest settlement']],
        ]);

        $selectedPeriod = ['selectedPeriodEnd' => '2026-07-31'];
        $this->actingAs($this->admin)->get(route('settlements.index', $selectedPeriod))
            ->assertOk()
            ->assertSee('href="'.route('settlements.show', ['settlement' => $olderSettlement->id] + $selectedPeriod).'"', false)
            ->assertDontSee('latest settlement');
        $this->actingAs($this->admin)->get(route('settlements.show', ['settlement' => $olderSettlement->id] + $selectedPeriod))
            ->assertOk()
            ->assertSee('href="'.route('settlements.index', $selectedPeriod).'"', false);
    }

    public function test_settlement_center_shows_historical_settlements_without_a_batch(): void
    {
        $settlement = Settlement::query()->create([
            'agent_id' => $this->agent->id,
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'status' => 'paid',
            'generation_status' => 'not_applicable',
            'snapshot' => ['source' => 'historical_import', 'agent' => ['name' => '未关联历史代理商']],
        ]);

        Livewire::actingAs($this->admin)
            ->test(SettlementCenter::class)
            ->assertSee(__('settlements.archive.center_heading'))
            ->assertSee('href="'.route('settlements.history').'"', false)
            ->assertDontSee('未关联历史代理商');

        $this->actingAs($this->admin)->get(route('settlements.history'))
            ->assertOk()
            ->assertSee(__('settlements.archive.title'))
            ->assertSee('未关联历史代理商')
            ->assertSee('href="'.route('settlements.show', $settlement->id).'"', false);
    }

    public function test_missing_agent_snapshots_fall_back_to_current_agent_reference_on_center_and_detail(): void
    {
        $settlement = Settlement::query()->create([
            'agent_id' => $this->agent->id,
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'status' => 'paid',
            'generation_status' => 'not_applicable',
            'snapshot' => ['source' => 'historical_import'],
        ]);
        Livewire::actingAs($this->admin)->test(SettlementCenter::class)->assertDontSee($this->agent->name);
        Livewire::actingAs($this->admin)
            ->test(SettlementHistory::class)
            ->assertSee($this->agent->name)
            ->set('search', $this->agent->name)
            ->assertSee($this->agent->name)
            ->assertSee('2026-06');
        $this->actingAs($this->admin)->get(route('settlements.show', $settlement))->assertOk()->assertSee($this->agent->name);
    }

    public function test_historical_agent_fallback_search_keeps_run_settlements_out_of_results(): void
    {
        Settlement::query()->create([
            'agent_id' => $this->agent->id,
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'status' => 'paid',
            'generation_status' => 'not_applicable',
            'snapshot' => ['source' => 'historical_import'],
        ]);
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
            'status' => 'paid',
            'generation_status' => 'generated',
            'snapshot' => ['agent' => ['name' => $this->agent->name]],
        ]);

        Livewire::actingAs($this->admin)
            ->test(SettlementHistory::class)
            ->set('search', $this->agent->name)
            ->assertSee('2026-06')
            ->assertDontSee('2026-07')
            ->set('businessFrom', '2026-06-01')
            ->set('businessTo', '2026-06-30')
            ->assertSee('2026-06')
            ->assertDontSee('2026-07');
    }

    public function test_historical_archive_supports_business_date_overlap_status_search_and_pagination(): void
    {
        Settlement::query()->create([
            'agent_id' => $this->agent->id,
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-31',
            'status' => 'paid',
            'generation_status' => 'not_applicable',
            'snapshot' => ['source' => 'historical_import', 'agent' => ['code' => 'HIST-A', 'name' => '归档代理商 A']],
        ]);
        Settlement::query()->create([
            'agent_id' => $this->agent->id,
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'status' => 'reconciled',
            'generation_status' => 'not_applicable',
            'snapshot' => ['source' => 'historical_import', 'agent' => ['code' => 'HIST-B', 'name' => '归档代理商 B']],
        ]);

        Livewire::actingAs($this->admin)
            ->test(SettlementHistory::class)
            ->assertSee('归档代理商 A')
            ->assertSee('归档代理商 B')
            ->assertSee(__('settlements.archive.business_from'))
            ->assertSee(__('settlements.archive.business_to'))
            ->assertDontSee('type="month"', false)
            ->set('businessFrom', '2026-05-01')
            ->set('businessTo', '2026-05-31')
            ->assertSee('归档代理商 A')
            ->assertDontSee('归档代理商 B')
            ->set('status', 'reconciled')
            ->assertDontSee('归档代理商 A')
            ->set('businessFrom', '')
            ->set('businessTo', '')
            ->set('status', '')
            ->set('search', 'HIST-B')
            ->assertSee('归档代理商 B')
            ->assertDontSee('归档代理商 A');

        Livewire::actingAs($this->admin)
            ->test(SettlementHistory::class)
            ->set('businessFrom', '2026-05-31')
            ->set('businessTo', '2026-06-01')
            ->assertSee('归档代理商 A')
            ->assertSee('归档代理商 B')
            ->set('businessFrom', '2026-07-01')
            ->set('businessTo', '2026-07-31')
            ->assertDontSee('归档代理商 A')
            ->assertDontSee('归档代理商 B');
    }

    public function test_historical_archive_paginates_records_without_loading_all_rows_for_the_table(): void
    {
        for ($index = 0; $index < 25; $index++) {
            $start = CarbonImmutable::create(2024, 1, 1)->addMonths($index);
            Settlement::query()->create([
                'agent_id' => $this->agent->id,
                'period_start' => $start->toDateString(),
                'period_end' => $start->endOfMonth()->toDateString(),
                'status' => 'paid',
                'generation_status' => 'not_applicable',
                'snapshot' => ['source' => 'historical_import'],
            ]);
        }

        Livewire::actingAs($this->admin)
            ->test(SettlementHistory::class)
            ->assertSee('2026-01')
            ->assertDontSee('2024-01')
            ->call('gotoPage', 2)
            ->assertSee('2024-01');
    }

    public function test_pending_and_failed_members_use_their_agent_reference_for_display(): void
    {
        $other = $this->createSettlementAgent(1);
        $run = SettlementRun::query()->create([
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'trigger_source' => 'manual',
            'status' => 'partial_failed',
            'total_agents' => 2,
            'failed_agents' => 1,
        ]);
        SettlementRunMember::query()->create([
            'settlement_run_id' => $run->id,
            'agent_id' => $this->agent->id,
            'outcome' => 'pending',
        ]);
        SettlementRunMember::query()->create([
            'settlement_run_id' => $run->id,
            'agent_id' => $other->id,
            'outcome' => 'failed',
            'error_message_key' => 'settlements.failure_reasons.legacy_unknown',
        ]);

        Livewire::actingAs($this->admin)
            ->test(SettlementCenter::class)
            ->assertSee($this->agent->code)
            ->assertSee($this->agent->name)
            ->assertSee($other->code)
            ->assertSee($other->name);
    }

    public function test_settlement_detail_links_snapshot_project_name_when_order_exists(): void
    {
        $orderId = $this->createCompletedOrder(10000);
        $commissionId = DB::table('order_commissions')->where('order_id', $orderId)->value('id');
        $settlement = Settlement::query()->create([
            'agent_id' => $this->agent->id,
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'status' => 'paid',
            'generation_status' => 'not_applicable',
            'snapshot' => ['source' => 'historical_import'],
        ]);
        DB::table('settlement_items')->insert([
            'settlement_id' => $settlement->id,
            'order_commission_id' => $commissionId,
            'consumption_krw' => 10000,
            'commission_krw' => 1000,
            'rule_snapshot' => json_encode([
                'order' => ['id' => $orderId, 'project_name' => '历史快照项目名称', 'completed_on' => '2026-06-15'],
                'rate_bps' => 1000,
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->admin)->get(route('settlements.show', $settlement))
            ->assertOk()
            ->assertSee('历史快照项目名称')
            ->assertSee('href="'.route('orders.show', $orderId).'"', false);
    }

    public function test_historical_settlement_keeps_missing_snapshot_order_visible_without_a_link(): void
    {
        $orderId = $this->createCompletedOrder(10000);
        $commissionId = DB::table('order_commissions')->where('order_id', $orderId)->value('id');
        $settlement = Settlement::query()->create([
            'agent_id' => $this->agent->id,
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'status' => 'paid',
            'generation_status' => 'not_applicable',
            'snapshot' => ['source' => 'historical_import'],
        ]);
        DB::table('settlement_items')->insert([
            'settlement_id' => $settlement->id,
            'order_commission_id' => $commissionId,
            'consumption_krw' => 10000,
            'commission_krw' => 1000,
            'rule_snapshot' => json_encode([
                'order' => ['id' => 999999, 'project_name' => '订单已删除但历史项目仍保留', 'completed_on' => '2026-06-15'],
                'rate_bps' => 1000,
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->admin)->get(route('settlements.show', $settlement))
            ->assertOk()
            ->assertSee('订单已删除但历史项目仍保留')
            ->assertSee(__('settlements.archive.archived_order'))
            ->assertDontSee('href="'.route('orders.show', 999999).'"', false);
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

    public function test_korean_failure_detail_and_xlsx_render_structured_reason_after_locale_restore(): void
    {
        Storage::fake('local');
        $run = SettlementRun::query()->create([
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'trigger_source' => 'manual',
            'status' => 'partial_failed',
            'total_agents' => 1,
            'failed_agents' => 1,
            'errors' => [(string) $this->agent->id => [
                'message_key' => 'settlements.failure_reasons.missing_commission_snapshot',
                'parameters' => ['order_id' => 181],
            ]],
        ]);

        $this->assertDatabaseHas('settlement_runs', ['id' => $run->id]);
        $this->admin->update(['preferred_locale' => 'ko_KR']);
        $previousLocale = App::getLocale();
        App::setLocale('ko_KR');
        try {
            $reason = __('settlements.failure_reasons.missing_commission_snapshot', ['order_id' => 181]);
            $this->actingAs($this->admin)->get(route('settlements.runs.failures', $run))
                ->assertOk()
                ->assertSee($reason);
            $path = app(SettlementRunFailureReportGenerator::class)->generate($run);
            $sheet = IOFactory::load(Storage::disk('local')->path($path))->getActiveSheet();
            $this->assertSame($reason, $sheet->getCell('F2')->getValue());
            $sheet->getParent()->disconnectWorksheets();
        } finally {
            App::setLocale($previousLocale);
        }
    }

    public function test_missing_agent_policy_is_persisted_as_a_structured_key_in_any_locale(): void
    {
        $agentWithoutGrade = $this->createSettlementAgent(100);
        $run = SettlementRun::query()->create([
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'trigger_source' => 'manual',
            'status' => 'running',
            'total_agents' => 1,
        ]);
        $previousLocale = App::getLocale();
        App::setLocale('ko_KR');
        try {
            try {
                app(SettlementGenerator::class)->generate($run->id, $agentWithoutGrade->id);
                $this->fail('Expected the missing policy grade to prevent settlement generation.');
            } catch (DomainException $exception) {
                app(SettlementGenerator::class)->markFailed($run->id, $agentWithoutGrade->id, $exception);
            }
        } finally {
            App::setLocale($previousLocale);
        }

        $failure = $run->fresh()->errors[(string) $agentWithoutGrade->id];
        $this->assertSame('settlements.failure_reasons.agent_policy_missing', $failure['message_key']);
        $this->assertSame([], $failure['parameters']);
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
            'errors' => [(string) $missingAgentId => 'SQLSTATE connection password=secret /srv/private'],
        ]);

        $this->actingAs($this->admin)->get(route('settlements.runs.failures', $run))
            ->assertOk()
            ->assertSee('未知')
            ->assertSee('代理商不存在或已删除')
            ->assertSee(__('settlements.failure_reasons.legacy_unknown'))
            ->assertDontSee('password=secret')
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
        $this->assertSame(__('settlements.failure_reasons.legacy_unknown'), $sheet->getCell('F2')->getValue());
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
        $firstFailure = $run->fresh()->errors[(string) $this->agent->id];
        $firstMessage = __('settlements.failure_reasons.unexpected', $firstFailure['parameters']);
        $generator->markFailed($run->id, $this->agent->id, new RuntimeException('unexpected failure two'));
        $secondFailure = $run->fresh()->errors[(string) $this->agent->id];
        $secondMessage = __('settlements.failure_reasons.unexpected', $secondFailure['parameters']);

        preg_match('/参考编号：(.+?)。$/u', $firstMessage, $firstMatches);
        preg_match('/参考编号：(.+?)。$/u', $secondMessage, $secondMatches);
        $this->assertSame('settlements.failure_reasons.unexpected', $firstFailure['message_key']);
        $this->assertSame('settlements.failure_reasons.unexpected', $secondFailure['message_key']);
        $references = [$firstFailure['parameters']['reference'] ?? null, $secondFailure['parameters']['reference'] ?? null];
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

    public function test_policy_grade_is_changed_only_by_an_explicit_manual_schedule(): void
    {
        $higher = PolicyGrade::query()->create([
            'policy_system_id' => $this->grade->policy_system_id,
            'name' => '升级级',
            'sort_order' => 20,
            'is_active' => true,
        ]);
        $this->createCompletedOrder(10000);
        $run = app(SettlementRunManager::class)->start('manual', $this->admin->id);
        $settlement = Settlement::query()->where('settlement_run_id', $run->id)->firstOrFail();
        $this->assertDatabaseMissing('agent_grade_assignments', ['agent_id' => $this->agent->id, 'policy_grade_id' => $higher->id]);
        $this->assertDatabaseCount('agent_grade_evaluations', 0);
        $this->assertDatabaseCount('settlement_grade_suggestions', 0);

        app(SettlementAgentGateway::class)->scheduleGrade(
            $this->agent->id,
            $higher->id,
            CarbonImmutable::parse('2026-09-01'),
            $this->admin->id,
            '人工确认升级',
        );
        $this->assertDatabaseHas('agent_grade_assignments', [
            'agent_id' => $this->agent->id,
            'policy_grade_id' => $higher->id,
            'effective_month' => '2026-09-01',
        ]);
    }

    public function test_krw_settlement_keeps_internal_commission_without_exchange_conversion(): void
    {
        $this->createCompletedOrder(10000);
        $run = app(SettlementRunManager::class)->start('manual', $this->admin->id);
        $settlement = Settlement::query()->where('settlement_run_id', $run->id)->firstOrFail();

        app(SettlementWorkflow::class)->approve($settlement->id, '', $this->admin->id, null, 'KRW');

        $settlement->refresh();
        $this->assertSame('KRW', $settlement->settlement_currency);
        $this->assertNull($settlement->exchange_rate);
        $this->assertNull($settlement->exchange_rate_krw_per_cny);
        $this->assertSame(0, (int) $settlement->payout_amount_cny_fen);
        $this->assertSame('approved', $settlement->status);
    }

    public function test_settlement_generation_never_creates_automatic_grade_evaluations_or_suggestions(): void
    {
        $this->createCompletedOrder(10000);
        $run = app(SettlementRunManager::class)->start('manual', $this->admin->id);
        Settlement::query()->where('settlement_run_id', $run->id)->firstOrFail();

        $this->assertDatabaseCount('agent_grade_evaluations', 0);
        $this->assertDatabaseCount('settlement_grade_suggestions', 0);
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
                'agent_id' => $this->agent->id,
                'project_name' => '性能项目',
                'amount_krw' => 10000,
                'completed_on' => '2026-07-15',
                'occurred_on' => '2026-07-15',
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
            agentId: $this->agent->id,
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
