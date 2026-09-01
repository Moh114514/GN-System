<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Agent\Infrastructure\Models\Agent;
use App\Modules\Agent\Infrastructure\Models\AgentBusinessGroupAssignment;
use App\Modules\Agent\Infrastructure\Models\AgentGradeAssignment;
use App\Modules\Agent\Infrastructure\Models\AgentTypeCode;
use App\Modules\Agent\Infrastructure\Models\PolicyGrade;
use App\Modules\Agent\Infrastructure\Models\PolicySystem;
use App\Modules\Auth\Infrastructure\Models\BusinessGroup;
use App\Modules\Auth\Infrastructure\Models\BusinessGroupMembership;
use App\Modules\Config\Infrastructure\Models\Institution;
use App\Modules\Customer\Application\Services\CustomerStatusManager;
use App\Modules\Customer\Infrastructure\Models\Customer;
use App\Modules\Customer\Infrastructure\Models\CustomerStatus;
use App\Modules\Order\Application\Data\InstitutionReturnUploadData;
use App\Modules\Order\Application\Services\InstitutionFormTemplateService;
use App\Modules\Order\Application\Services\InstitutionReturnParser;
use App\Modules\Order\Application\Services\InstitutionReturnProcessor;
use App\Modules\Order\Infrastructure\Models\InstitutionReturnFile;
use App\Modules\Order\Infrastructure\Models\Order;
use App\Modules\Order\Infrastructure\Models\OrderItem;
use App\Modules\Order\Presentation\Livewire\CustomerOrderRegistration;
use App\Modules\Settlement\Application\Contracts\DailyCommissionGateway;
use App\Modules\Settlement\Infrastructure\Models\CommissionRule;
use Database\Seeders\PhaseTwoReferenceDataSeeder;
use DateTimeImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Mockery;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class InstitutionReturnFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(PhaseTwoReferenceDataSeeder::class);
    }

    public function test_valid_return_creates_one_order_items_commission_customer_completion_and_two_reminders(): void
    {
        $user = User::factory()->create();
        $institution = Institution::query()->firstOrFail();
        $agent = $this->agent($institution);
        $customer = $this->customer($agent, $user);
        $contents = $this->workbook($institution->id, $customer->id, '2026-09-01', 2, 150000, 300000);

        $orderId = app(InstitutionReturnProcessor::class)->upload(new InstitutionReturnUploadData(
            institutionId: $institution->id,
            customerId: $customer->id,
            originalName: '机构回传.xlsx',
            extension: 'xlsx',
            mimeType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            contents: $contents,
            actorId: $user->id,
            ipAddress: '127.0.0.1',
        ));

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'customer_id' => $customer->id,
            'institution_id' => $institution->id,
            'agent_id' => $agent->id,
            'occurred_on' => '2026-09-01',
            'completed_on' => '2026-09-01',
            'status' => 'completed',
            'record_status' => 'active',
        ]);
        $this->assertSame(1, OrderItem::query()->where('order_id', $orderId)->count());
        $this->assertDatabaseHas('order_commissions', ['order_id' => $orderId, 'amount_krw' => 30000]);
        $this->assertDatabaseCount('reminders', 2);
        $this->assertDatabaseHas('institution_return_files', ['status' => 'processed', 'original_name' => '机构回传.xlsx']);
        $this->assertDatabaseHas('customer_statuses', ['key' => 'treatment_completed']);
        $this->assertSame('treatment_completed', $customer->refresh()->currentStatus?->key);
        $this->assertNotNull($customer->treatment_completed_at);
    }

    public function test_customer_registration_upload_shows_success_and_dispatches_refresh_event(): void
    {
        $user = User::factory()->create();
        $institution = Institution::query()->firstOrFail();
        $agent = $this->agent($institution);
        $customer = $this->customer($agent, $user);
        $arrived = CustomerStatus::query()->where('key', 'arrived')->firstOrFail();
        app(CustomerStatusManager::class)->change($customer->id, $arrived->id, '客户已到院', $user, null);
        $contents = $this->workbook($institution->id, $customer->id, '2026-09-01', 1, 200000, 200000);

        Livewire::actingAs($user)
            ->test(CustomerOrderRegistration::class, ['customerId' => $customer->id])
            ->set('institutionId', (string) $institution->id)
            ->set('upload', UploadedFile::fake()->createWithContent('登记订单.xlsx', $contents))
            ->call('uploadReturn')
            ->assertHasNoErrors()
            ->assertSet('status', 'success')
            ->assertSet('successResult.project_name', '皮肤管理')
            ->assertDispatched('customer-order-registered');

        $this->assertDatabaseCount('orders', 1);
    }

    public function test_amount_mismatch_is_rejected_without_an_order(): void
    {
        $user = User::factory()->create();
        $institution = Institution::query()->firstOrFail();
        $agent = $this->agent($institution);
        $customer = $this->customer($agent, $user);
        $contents = $this->workbook($institution->id, $customer->id, '2026/09/01', 1, 100000, 90000);

        $this->expectException(DomainException::class);
        app(InstitutionReturnProcessor::class)->upload(new InstitutionReturnUploadData(
            institutionId: $institution->id,
            customerId: $customer->id,
            originalName: '金额错误.xlsx',
            extension: 'xlsx',
            mimeType: null,
            contents: $contents,
            actorId: $user->id,
            ipAddress: null,
        ));

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseHas('institution_return_files', ['status' => 'failed', 'failure_code' => 'processing_failed']);
    }

    public function test_metadata_tampering_is_rejected_without_creating_an_order(): void
    {
        $user = User::factory()->create();
        $institution = Institution::query()->firstOrFail();
        $agent = $this->agent($institution);
        $customer = $this->customer($agent, $user);
        $generated = app(InstitutionFormTemplateService::class)->generate($institution->id, $customer->id);
        $spreadsheet = IOFactory::load($generated['path']);
        $metaSheet = $spreadsheet->getSheetByName('__GN_META');
        for ($row = 2; $row <= $metaSheet->getHighestRow(); $row++) {
            if ((string) $metaSheet->getCell("A{$row}")->getValue() === 'customer_id') {
                $metaSheet->setCellValue("B{$row}", (string) ($customer->id + 1));
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'gn-test-tampered-');
        (new Xlsx($spreadsheet))->save($path);
        $contents = file_get_contents($path);
        @unlink($path);
        @unlink($generated['path']);

        $this->expectException(DomainException::class);
        app(InstitutionReturnProcessor::class)->upload(new InstitutionReturnUploadData(
            institutionId: $institution->id,
            customerId: $customer->id,
            originalName: '元数据篡改.xlsx',
            extension: 'xlsx',
            mimeType: null,
            contents: $contents === false ? '' : $contents,
            actorId: $user->id,
            ipAddress: null,
        ));

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_parser_accepts_excel_serial_date_objects_and_compatible_strings(): void
    {
        $user = User::factory()->create();
        $institution = Institution::query()->firstOrFail();
        $agent = $this->agent($institution);
        $customer = $this->customer($agent, $user);

        $cases = [
            [ExcelDate::PHPToExcel(new DateTimeImmutable('2026-08-31')), '2026-08-31'],
            [new DateTimeImmutable('2026-09-01'), '2026-09-01'],
            ['2026年09月02日', '2026-09-02'],
        ];

        foreach ($cases as [$date, $expected]) {
            $contents = $this->workbook($institution->id, $customer->id, $date, 1, 100000, 100000);
            $parsed = app(InstitutionReturnParser::class)->parse($contents, 'xlsx', [
                'institution_id' => $institution->id,
                'customer_id' => $customer->id,
                'customer_code' => $customer->code,
                'customer_name' => $customer->name,
            ]);

            $this->assertSame($expected, $parsed['occurred_on']->toDateString());
        }
    }

    public function test_new_order_facts_migration_blocks_pending_history_and_refuses_fact_rollback(): void
    {
        $user = User::factory()->create();
        $institution = Institution::query()->firstOrFail();
        $agent = $this->agent($institution);
        $customer = $this->customer($agent, $user);
        Order::query()->create([
            'customer_id' => $customer->id,
            'institution_id' => $institution->id,
            'agent_id' => $agent->id,
            'project_name' => '迁移阻断测试',
            'amount_krw' => 100000,
            'status' => 'pending',
            'owner_id' => $user->id,
        ]);

        $migration = require database_path('migrations/2026_08_24_000200_add_institution_return_order_facts.php');
        try {
            $migration->up();
            $this->fail('The migration should refuse pending orders.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('pending orders', $exception->getMessage());
        }

        $order = Order::query()->firstOrFail();
        $order->update(['status' => 'cancelled', 'occurred_on' => '2026-09-01']);
        try {
            $migration->down();
            $this->fail('The migration should refuse rollback of order facts.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('occurred_on', $exception->getMessage());
        }
    }

    public function test_same_file_and_same_form_are_not_processed_twice(): void
    {
        $user = User::factory()->create();
        $institution = Institution::query()->firstOrFail();
        $agent = $this->agent($institution);
        $customer = $this->customer($agent, $user);
        $contents = $this->workbook($institution->id, $customer->id, '2026-09-01', 1, 200000, 200000);
        $data = new InstitutionReturnUploadData($institution->id, $customer->id, '重复.xlsx', 'xlsx', null, $contents, $user->id, null);

        app(InstitutionReturnProcessor::class)->upload($data);
        $this->expectException(DomainException::class);
        app(InstitutionReturnProcessor::class)->upload($data);

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('institution_return_files', 1);
    }

    public function test_commission_failure_rolls_back_order_customer_status_and_reminders_but_keeps_failed_file(): void
    {
        $user = User::factory()->create();
        $institution = Institution::query()->firstOrFail();
        $agent = $this->agent($institution);
        $customer = $this->customer($agent, $user);
        $contents = $this->workbook($institution->id, $customer->id, '2026-09-01', 1, 100000, 100000);
        $gateway = Mockery::mock(DailyCommissionGateway::class);
        $gateway->shouldReceive('recordForCompletedOrder')->once()->andThrow(new DomainException('commission failure'));
        app()->instance(DailyCommissionGateway::class, $gateway);

        $this->expectException(DomainException::class);
        app(InstitutionReturnProcessor::class)->upload(new InstitutionReturnUploadData($institution->id, $customer->id, '失败.xlsx', 'xlsx', null, $contents, $user->id, null));

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_commissions', 0);
        $this->assertDatabaseCount('reminders', 0);
        $this->assertNull($customer->refresh()->current_status_id);
        $this->assertDatabaseHas('institution_return_files', ['status' => 'failed']);
    }

    public function test_original_return_file_is_private_and_available_to_the_customer_scope(): void
    {
        $user = User::factory()->create();
        $institution = Institution::query()->firstOrFail();
        $agent = $this->agent($institution);
        $customer = $this->customer($agent, $user);
        $contents = $this->workbook($institution->id, $customer->id, '2026-09-01', 1, 100000, 100000);
        $orderId = app(InstitutionReturnProcessor::class)->upload(new InstitutionReturnUploadData($institution->id, $customer->id, '原始回传.xlsx', 'xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $contents, $user->id, null));
        $file = InstitutionReturnFile::query()->firstOrFail();

        $response = $this->actingAs($user)->get(route('institution-returns.download', $file->id));

        $response->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="原始回传.xlsx"');
        $this->assertSame($contents, $response->getContent());
        $this->assertSame($orderId, Order::query()->value('id'));
    }

    private function workbook(int $institutionId, int $customerId, mixed $date, int $quantity, int $unitPrice, int $amount): string
    {
        $generated = app(InstitutionFormTemplateService::class)->generate($institutionId, $customerId);
        $spreadsheet = IOFactory::load($generated['path']);
        $sheet = $spreadsheet->getSheetByName('机构回传');
        $sheet->fromArray([
            $sheet->getCell('A2')->getValue(),
            $sheet->getCell('B2')->getValue(),
            $date,
            '皮肤管理',
            '标准项目',
            $quantity,
            $unitPrice,
            $amount,
            '机构回传测试',
        ], null, 'A2');
        $path = tempnam(sys_get_temp_dir(), 'gn-test-return-');
        (new Xlsx($spreadsheet))->save($path);
        @unlink($generated['path']);
        $contents = file_get_contents($path);
        @unlink($path);

        return $contents === false ? '' : $contents;
    }

    private function agent(Institution $institution): Agent
    {
        $type = AgentTypeCode::query()->firstOrFail();
        $agent = Agent::query()->create([
            'agent_type_code_id' => $type->id,
            'code' => 'TEST-JG-PR4',
            'name' => 'PR4 测试代理商',
            'cooperation_started_on' => '2026-01-01',
            'cooperation_status' => 'active',
        ]);
        $system = PolicySystem::query()->firstOrCreate(['name' => 'PR4 测试政策'], ['is_active' => true]);
        $grade = PolicyGrade::query()->create([
            'policy_system_id' => $system->id,
            'name' => 'PR4 测试等级',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        AgentGradeAssignment::query()->create([
            'agent_id' => $agent->id,
            'policy_grade_id' => $grade->id,
            'effective_month' => '2026-01-01',
        ]);
        CommissionRule::query()->create([
            'policy_grade_id' => $grade->id,
            'institution_id' => $institution->id,
            'rate_bps' => 1000,
            'effective_month' => '2026-01-01',
            'is_active' => true,
        ]);

        return $agent;
    }

    private function customer(Agent $agent, User $owner): Customer
    {
        $group = BusinessGroup::query()->create([
            'code' => 'PR4-RETURN-GROUP',
            'name' => 'PR4 回传测试业务组',
            'is_active' => true,
            'created_by' => $owner->id,
        ]);
        BusinessGroupMembership::query()->create([
            'business_group_id' => $group->id,
            'user_id' => $owner->id,
            'member_role' => 'customer_service',
            'effective_from' => '2026-01-01',
            'assigned_by' => $owner->id,
            'reason' => 'institution return test scope',
        ]);
        AgentBusinessGroupAssignment::query()->create([
            'agent_id' => $agent->id,
            'business_group_id' => $group->id,
            'effective_from' => '2026-01-01',
            'assigned_by' => $owner->id,
            'reason' => 'institution return test scope',
        ]);

        return Customer::query()->create([
            'code' => 'TEST-JG-PR4-0001',
            'name' => 'PR4 测试客户',
            'source_agent_id' => $agent->id,
            'owner_id' => $owner->id,
        ]);
    }
}
