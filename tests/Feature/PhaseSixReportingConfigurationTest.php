<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Auth\Application\Contracts\UserManagementGateway;
use App\Modules\Config\Application\Services\ConfigurationCatalogManager;
use App\Modules\Customer\Application\Contracts\ConfigurationHistoryGateway as CustomerConfigurationHistory;
use App\Modules\Customer\Domain\BlindIndex;
use App\Modules\Customer\Infrastructure\Models\Customer;
use App\Modules\Customer\Infrastructure\Models\CustomerIdentityDocument;
use App\Modules\Customer\Infrastructure\Models\CustomerStatus;
use App\Modules\Order\Infrastructure\Models\Order;
use App\Modules\Report\Application\Services\DashboardExportGenerator;
use App\Modules\Report\Application\Services\DashboardRangeFactory;
use App\Modules\Report\Application\Services\DashboardService;
use App\Modules\Report\Application\Services\ReportExportManager;
use App\Modules\Report\Application\Services\ReportSearch;
use App\Modules\Report\Infrastructure\Models\ReportExport;
use App\Modules\Report\Jobs\GenerateReportExport;
use App\Modules\Report\Presentation\Livewire\Dashboard;
use App\Modules\Settlement\Infrastructure\Models\OrderCommission;
use Carbon\CarbonImmutable;
use Database\Seeders\PhaseTwoReferenceDataSeeder;
use DomainException;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class PhaseSixReportingConfigurationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Customer $customer;

    private int $institutionId;

    private int $agentId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PhaseTwoReferenceDataSeeder::class);
        $this->user = User::factory()->create();
        $this->institutionId = (int) DB::table('institutions')->value('id');
        $sourceId = (int) DB::table('direct_sales_sources')->insertGetId([
            'code' => 'P6WEB',
            'name' => 'Phase Six Web',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->agentId = (int) DB::table('agents')->insertGetId([
            'agent_type_code_id' => DB::table('agent_type_codes')->value('id'),
            'code' => 'P6-AGENT',
            'name' => 'Phase Six Agent',
            'cooperation_status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $statusId = (int) CustomerStatus::query()->where('key', 'interested')->value('id');
        $this->customer = Customer::query()->create([
            'code' => 'WEB-000001',
            'name' => 'Phase Six Customer',
            'original_channel' => 'direct',
            'source_direct_sales_id' => $sourceId,
            'current_status_id' => $statusId,
            'owner_id' => $this->user->id,
        ]);
        CustomerIdentityDocument::query()->create([
            'customer_id' => $this->customer->id,
            'type' => 'passport_or_residence_card',
            'number_encrypted' => 'M123-4567',
            'lookup_hash' => app(BlindIndex::class)->for('M123-4567'),
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_nine_dimension_order_search_uses_exact_passport_index_and_fixed_pagination(): void
    {
        $completedAt = CarbonImmutable::parse('2026-07-15 14:30:00', 'Asia/Shanghai');
        Order::query()->create([
            'customer_id' => $this->customer->id,
            'institution_id' => $this->institutionId,
            'channel' => 'direct',
            'direct_sales_source_id' => DB::table('direct_sales_sources')->value('id'),
            'project_name' => 'Skin Care',
            'treatment_project_snapshot' => 'Skin Care',
            'amount_krw' => 1200000,
            'completed_on' => $completedAt->toDateString(),
            'completed_at' => $completedAt,
            'completion_precision' => 'datetime',
            'translator_name' => 'Kim',
            'owner_id' => $this->user->id,
            'status' => 'completed',
        ]);
        Order::query()->create([
            'customer_id' => $this->customer->id,
            'institution_id' => $this->institutionId,
            'channel' => 'direct',
            'direct_sales_source_id' => DB::table('direct_sales_sources')->value('id'),
            'project_name' => 'Other',
            'amount_krw' => 1,
            'completed_on' => '2026-06-01',
            'completed_at' => CarbonImmutable::parse('2026-06-01 08:00:00', 'Asia/Shanghai'),
            'completion_precision' => 'datetime',
            'owner_id' => $this->user->id,
            'status' => 'completed',
        ]);

        $result = app(ReportSearch::class)->paginate([
            'completed_from' => '2026-07-15',
            'completed_to' => '2026-07-15',
            'time_from' => '14:30',
            'time_to' => '14:30',
            'customer_id' => $this->customer->id,
            'agent_id' => '',
            'project_name' => 'Skin',
            'institution_id' => $this->institutionId,
            'translator_name' => 'Kim',
            'amount_min' => 1200000,
            'amount_max' => 1200000,
            'passport' => 'm123 4567',
            'sort_field' => 'amount',
            'sort_direction' => 'asc',
        ], 50, 1);

        $this->assertSame(1, $result['page']->total);
        $this->assertSame(50, $result['page']->perPage);
        $this->assertSame('Phase Six Customer', $result['rows'][0]['customer']);
        $this->assertArrayNotHasKey('passport', $result['rows'][0]);

        $missing = app(ReportSearch::class)->paginate(['passport' => 'UNKNOWN'], 50, 1);
        $this->assertSame(0, $missing['page']->total);
    }

    public function test_query_export_is_private_hashed_retryable_and_expires(): void
    {
        Storage::fake('local');
        Queue::fake();
        $export = app(ReportExportManager::class)->queueSearch($this->user, []);
        Queue::assertPushed(GenerateReportExport::class);

        (new GenerateReportExport($export->id))->handle(app(ReportSearch::class));
        $export->refresh();
        $this->assertSame('completed', $export->status);
        $this->assertNotNull($export->sha256);
        Storage::disk('local')->assertExists($export->path);
        $sheet = IOFactory::load(Storage::disk('local')->path($export->path))->getActiveSheet();
        $this->assertSame(
            ['成交时间', '客户', '代理商', '施术项目', '机构', '翻译姓名', '成交金额 KRW'],
            $sheet->rangeToArray('A1:G1', null, true, true, false)[0],
        );

        $other = User::factory()->create();
        $this->actingAs($other)->get(route('reports.exports.download', $export))->assertForbidden();
        $this->actingAs($this->user)->get(route('reports.exports.download', $export))->assertOk();

        $export->update(['expires_at' => now()->subMinute()]);
        $this->artisan('app:purge-report-exports')->assertSuccessful();
        $this->assertSame('expired', $export->fresh()->status);
        Storage::disk('local')->assertMissing((string) $export->path);
    }

    public function test_dashboard_uses_real_snapshot_for_metrics_charts_and_server_exports(): void
    {
        Storage::fake('local');
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-30 12:00:00', 'Asia/Shanghai'));
        $order = Order::query()->create([
            'customer_id' => $this->customer->id,
            'institution_id' => $this->institutionId,
            'channel' => 'direct',
            'direct_sales_source_id' => DB::table('direct_sales_sources')->value('id'),
            'project_name' => 'Dashboard Project',
            'amount_krw' => 880000,
            'completed_on' => '2026-07-30',
            'completed_at' => CarbonImmutable::now(),
            'completion_precision' => 'datetime',
            'owner_id' => $this->user->id,
            'status' => 'completed',
        ]);
        OrderCommission::query()->create([
            'order_id' => $order->id,
            'agent_id' => $this->agentId,
            'rate_bps' => 1000,
            'amount_krw' => 88000,
            'rule_snapshot' => ['test' => true],
        ]);
        DB::table('reminders')->insert([
            'customer_id' => $this->customer->id,
            'source_type' => 'manual',
            'reminder_type' => 'manual',
            'title' => 'Overdue',
            'priority' => 3,
            'due_at' => now()->subHour(),
            'status' => 'pending',
            'notification_status' => 'pending',
            'dedupe_key' => hash('sha256', 'phase-six-overdue'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $range = app(DashboardRangeFactory::class)->make('month');
        $snapshot = app(DashboardService::class)->snapshot($range, true)->toArray();
        $this->assertSame(880000, $snapshot['metrics']['completed_amount']['value']);
        $this->assertSame(1, $snapshot['metrics']['overdue_customers']['value']);
        $this->assertCount(8, $snapshot['charts']);

        $html = app(DashboardExportGenerator::class)->generate($this->user, 'html', $snapshot);
        $pdf = app(DashboardExportGenerator::class)->generate($this->user, 'pdf', $snapshot);
        $this->assertSame($html->data_snapshot['generated_at'], $pdf->data_snapshot['generated_at']);
        Storage::disk('local')->assertExists($html->path);
        Storage::disk('local')->assertExists($pdf->path);

        $this->actingAs($this->user)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('数据看板')
            ->assertSee('代理商推广费排行')
            ->assertSee('data-dashboard-chart', false)
            ->assertDontSee('演示数据');

        foreach (['html', 'pdf'] as $format) {
            $component = Livewire::actingAs($this->user)->test(Dashboard::class);
            $component->call('export', $format);
            $componentExport = ReportExport::query()
                ->where('created_by', $this->user->id)
                ->where('kind', 'dashboard')
                ->where('format', $format)
                ->latest('id')
                ->firstOrFail();

            $component->assertRedirect(route('reports.exports.download', $componentExport));
            Storage::disk('local')->assertExists($componentExport->path);
        }
    }

    public function test_configuration_catalog_user_safeguards_and_customer_snapshot_rollback(): void
    {
        Notification::fake();
        $admin = User::factory()->superAdmin()->withTwoFactor()->create();
        $catalog = app(ConfigurationCatalogManager::class);
        $catalog->saveInstitution(null, 'P6', 'Phase Six Hospital', 'Address', 'Contact', '10086', $admin->id, null);
        $this->assertDatabaseHas('institutions', ['code' => 'P6', 'address' => 'Address', 'is_active' => true]);
        $catalog->saveDictionaryItem(null, 'treatment_project', 'SKIN', 'Skin Project', $admin->id, null);
        $catalog->saveParameter('report_default_per_page', 75, $admin->id, null);
        $this->assertEquals(75, DB::table('system_parameters')->where('key', 'report_default_per_page')->value('value'));

        $users = app(UserManagementGateway::class);
        $invited = $users->invite('Invited User', 'invite@example.com', false, $admin->id, null);
        $this->assertSame('sent', $invited['invitation_status']);
        $invitedUser = User::query()->findOrFail($invited['id']);
        Notification::assertSentTo($invitedUser, ResetPassword::class);
        $users->setActive($invitedUser->id, false, $admin->id, null);
        $this->assertFalse($invitedUser->fresh()->is_active);
        $this->assertSame(2, $invitedUser->fresh()->session_version);

        $status = CustomerStatus::query()->where('key', 'interested')->firstOrFail();
        $history = app(CustomerConfigurationHistory::class);
        $snapshotId = $history->capture($admin->id);
        $status->update(['name' => 'Changed']);
        OrderCommission::query()->first()?->update(['amount_krw' => 123]);
        $history->rollback($snapshotId, $admin->id, null);
        $this->assertNotSame('Changed', $status->fresh()->name);
        $this->assertDatabaseHas('customer_configuration_snapshots', [
            'action' => 'rollback',
            'target_snapshot_id' => $snapshotId,
        ]);
    }

    public function test_user_management_protects_self_and_last_active_super_admin(): void
    {
        $admin = User::factory()->superAdmin()->withTwoFactor()->create();
        $users = app(UserManagementGateway::class);

        try {
            $users->setActive($admin->id, false, $admin->id, null);
            $this->fail('Self-deactivation should have failed.');
        } catch (DomainException $exception) {
            $this->assertSame('不能停用当前登录账号。', $exception->getMessage());
        }

        $actor = User::factory()->create();
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('不能降级最后一个启用中的超级管理员。');
        $users->changeRole($admin->id, false, $actor->id, null);
    }

    public function test_phase_six_migration_backfills_local_midnight_and_can_roll_back_without_dropping_completed_on(): void
    {
        $order = Order::query()->create([
            'customer_id' => $this->customer->id,
            'institution_id' => $this->institutionId,
            'channel' => 'direct',
            'direct_sales_source_id' => DB::table('direct_sales_sources')->value('id'),
            'project_name' => 'Historical Project',
            'amount_krw' => 1,
            'completed_on' => '2026-07-01',
            'completed_at' => null,
            'completion_precision' => 'date',
            'owner_id' => $this->user->id,
            'status' => 'completed',
        ]);
        $migration = require database_path('migrations/2026_07_30_030000_add_phase_six_reporting_and_configuration.php');

        $migration->down();
        $this->assertTrue(Schema::hasColumn('orders', 'completed_on'));
        $this->assertFalse(Schema::hasColumn('orders', 'completed_at'));

        $migration->up();
        $backfilled = Order::query()->findOrFail($order->id);
        $this->assertSame(
            '2026-07-01 00:00:00',
            $backfilled->completed_at?->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s'),
        );
        $this->assertSame('date', $backfilled->completion_precision);
        $this->assertSame('Historical Project', $backfilled->treatment_project_snapshot);
        $this->assertSame(4, DB::table('pg_indexes')
            ->where('schemaname', 'public')
            ->whereIn('indexname', [
                'orders_completed_at_index',
                'orders_agent_completed_at_index',
                'orders_institution_completed_at_index',
                'orders_amount_krw_index',
            ])
            ->count());
    }

    public function test_phase_six_navigation_and_configuration_children_return_to_parent(): void
    {
        $admin = User::factory()->superAdmin()->withTwoFactor()->create();
        $this->actingAs($this->user)->get(route('reports.search'))
            ->assertOk()
            ->assertSee('多维查询');

        foreach ([
            'configuration.catalog',
            'configuration.users',
            'configuration.history',
            'direct-sales-sources.index',
        ] as $route) {
            $this->actingAs($admin)->get(route($route))
                ->assertOk()
                ->assertSee('返回配置中心')
                ->assertSee('href="'.route('configuration.index').'"', false);
        }
    }
}
