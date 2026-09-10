<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Agent\Infrastructure\Models\Agent;
use App\Modules\Agent\Infrastructure\Models\AgentBusinessGroupAssignment;
use App\Modules\Agent\Infrastructure\Models\AgentTypeCode;
use App\Modules\Auth\Domain\UserRole;
use App\Modules\Auth\Infrastructure\Models\BusinessGroup;
use App\Modules\Auth\Infrastructure\Models\BusinessGroupMembership;
use App\Modules\Config\Infrastructure\Models\Institution;
use App\Modules\Customer\Infrastructure\Models\Customer;
use App\Modules\Customer\Infrastructure\Models\CustomerStatus;
use App\Modules\Order\Application\Contracts\ReportOrderReader;
use App\Modules\Order\Infrastructure\Models\Order;
use App\Modules\Report\Application\Services\InstitutionMonthlySalesDetailService;
use App\Modules\Report\Application\Services\InstitutionMonthlySalesService;
use App\Modules\Report\Application\Services\ReportExportManager;
use App\Modules\Report\Presentation\Livewire\InstitutionMonthlySales;
use Carbon\CarbonImmutable;
use Database\Seeders\PhaseTwoReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class InstitutionMonthlySalesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Agent $agent;

    private Institution $institutionA;

    private Institution $institutionB;

    private Institution $institutionC;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-08-15 10:00:00');
        $this->seed(PhaseTwoReferenceDataSeeder::class);
        $this->admin = User::factory()->superAdmin()->withTwoFactor()->create();
        $typeId = AgentTypeCode::query()->value('id');
        $this->agent = Agent::query()->create([
            'agent_type_code_id' => $typeId,
            'code' => 'SALES-A',
            'name' => '销售代理商 A',
            'cooperation_status' => 'active',
        ]);
        $this->institutionA = Institution::query()->where('code', 'DOD')->firstOrFail();
        $this->institutionB = Institution::query()->where('code', 'GRAYCITY')->firstOrFail();
        $this->institutionC = Institution::query()->where('code', 'BLANCHE')->firstOrFail();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_database_aggregate_uses_occurred_on_and_only_completed_active_orders(): void
    {
        $customerA = $this->customer('销售客户 A');
        $customerB = $this->customer('销售客户 B');
        $this->order($customerA, $this->institutionA, 1_000_000, '2026-08-31', 'completed', 'active');
        $this->order($customerA, $this->institutionA, 2_000_000, '2026-08-01', 'completed', 'active', '2026-09-02 10:00:00');
        $this->order($customerB, $this->institutionA, 3_000_000, '2026-08-15', 'completed', 'active');
        $this->order($customerB, $this->institutionB, 4_000_000, '2026-08-20', 'completed', 'active');
        $this->order($customerB, $this->institutionA, 5_000_000, '2026-08-20', 'pending', 'active');
        $this->order($customerB, $this->institutionA, 6_000_000, '2026-08-20', 'completed', 'voided');
        $this->order($customerB, $this->institutionA, 7_000_000, null, 'completed', 'active');
        $deleted = $this->order($customerB, $this->institutionA, 8_000_000, '2026-08-20', 'completed', 'active');
        $deleted->delete();
        $this->order($customerB, $this->institutionA, 9_000_000, '2026-09-01', 'completed', 'active');

        $this->actingAs($this->admin);
        $summary = app(InstitutionMonthlySalesService::class)->summary('2026-08');

        $this->assertSame(3, count($summary->rows));
        $this->assertSame(3, $summary->totalCustomers);
        $this->assertSame(4, $summary->totalOrders);
        $this->assertSame(10_000_000, $summary->totalAmountKrw);
        $rows = collect($summary->rows)->keyBy('institutionId');
        $this->assertSame(6_000_000, $rows[$this->institutionA->id]->amountKrw);
        $this->assertSame(3, $rows[$this->institutionA->id]->orderCount);
        $this->assertSame(2, $rows[$this->institutionA->id]->customerCount);
        $this->assertSame(4_000_000, $rows[$this->institutionB->id]->amountKrw);
        $this->assertSame(0, $rows[$this->institutionC->id]->amountKrw);
        $this->assertSame(0, $rows[$this->institutionC->id]->orderCount);
        $this->assertSame(0, $rows[$this->institutionC->id]->customerCount);

        $institutionSummary = app(InstitutionMonthlySalesService::class)->summary('2026-08', $this->institutionA->id);
        $this->assertSame(6_000_000, $institutionSummary->totalAmountKrw);
        $this->assertCount(1, $institutionSummary->rows);
        $this->assertSame($this->institutionA->id, $institutionSummary->rows[0]->institutionId);
    }

    public function test_active_institution_without_orders_is_listed_and_detail_is_empty(): void
    {
        $customer = $this->customer('零订单客户');
        $this->order($customer, $this->institutionA, 1_000_000, '2026-08-15', 'completed', 'active');

        $this->actingAs($this->admin);
        $summary = app(InstitutionMonthlySalesService::class)->summary('2026-08');
        $zeroRow = collect($summary->rows)->firstWhere('institutionId', $this->institutionC->id);

        $this->assertNotNull($zeroRow);
        $this->assertSame(0, $zeroRow->customerCount);
        $this->assertSame(0, $zeroRow->orderCount);
        $this->assertSame(0, $zeroRow->amountKrw);
        $this->assertSame(1, $summary->totalCustomers);
        $this->assertSame(1, $summary->totalOrders);
        $this->assertSame(1_000_000, $summary->totalAmountKrw);

        $detail = app(InstitutionMonthlySalesDetailService::class)->detail('2026-08', $this->institutionC->id);
        $this->assertSame(0, $detail['customer_count']);
        $this->assertSame(0, $detail['order_count']);
        $this->assertSame(0, $detail['agent_count']);
        $this->assertSame(0, $detail['amount_krw']);
        $this->assertSame([], $detail['agents']);
        $this->assertSame([], $detail['orders']);

        $this->get(route('reports.institution-sales.show', [
            'institution' => $this->institutionC->id,
            'month' => '2026-08',
        ]))->assertOk()
            ->assertSee('机构销售详情')
            ->assertSee('返回机构销售额')
            ->assertSee('data-page-back-path="/reports/institution-sales"', false);
    }

    public function test_institution_detail_combines_agent_and_order_facts(): void
    {
        $customer = $this->customer('详情客户');
        $this->order($customer, $this->institutionA, 1_250_000, '2026-08-15', 'completed', 'active');

        $this->actingAs($this->admin);
        $detail = app(InstitutionMonthlySalesDetailService::class)->detail('2026-08', $this->institutionA->id);

        $this->assertSame(1, $detail['customer_count']);
        $this->assertSame(1, $detail['order_count']);
        $this->assertSame(1, $detail['agent_count']);
        $this->assertSame(1_250_000, $detail['amount_krw']);
        $this->assertSame('详情客户', $detail['orders'][0]['customer']);
        $this->assertSame('销售代理商 A', $detail['orders'][0]['agent']);
        $this->assertSame(1_250_000, $detail['agents'][0]['amount_krw']);
        $this->assertSame(100.0, $detail['agents'][0]['share']);
    }

    public function test_order_reader_and_page_respect_effective_business_scope(): void
    {
        $bd = User::factory()->create(['role' => UserRole::BdManager]);
        $groupA = BusinessGroup::query()->create([
            'code' => 'SALES-GROUP-A',
            'name' => '销售范围 A',
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);
        BusinessGroupMembership::query()->create([
            'business_group_id' => $groupA->id,
            'user_id' => $bd->id,
            'member_role' => 'bd_manager',
            'effective_from' => '2026-01-01',
            'effective_until' => null,
            'assigned_by' => $this->admin->id,
            'reason' => 'sales scope test',
        ]);
        AgentBusinessGroupAssignment::query()->create([
            'agent_id' => $this->agent->id,
            'business_group_id' => $groupA->id,
            'effective_from' => '2026-01-01',
            'effective_until' => null,
            'assigned_by' => $this->admin->id,
            'reason' => 'sales scope test',
        ]);
        $otherAgent = Agent::query()->create([
            'agent_type_code_id' => AgentTypeCode::query()->value('id'),
            'code' => 'SALES-B',
            'name' => '销售代理商 B',
            'cooperation_status' => 'active',
        ]);
        $customerA = $this->customer('范围客户 A', $this->agent);
        $customerB = $this->customer('范围客户 B', $otherAgent);
        $this->order($customerA, $this->institutionA, 1_000_000, '2026-08-10', 'completed', 'active', null, $this->agent);
        $this->order($customerB, $this->institutionA, 9_000_000, '2026-08-10', 'completed', 'active', null, $otherAgent);

        $this->actingAs($bd);
        $summary = app(InstitutionMonthlySalesService::class)->summary('2026-08');
        $this->assertSame(1_000_000, $summary->totalAmountKrw);
        $this->assertSame(1, $summary->totalOrders);
        $this->assertSame(1, $summary->totalCustomers);
        $this->assertSame([$this->institutionA->id], array_column(
            app(InstitutionMonthlySalesService::class)->institutionOptions(),
            'id',
        ));
        $this->assertNotEmpty(app(ReportOrderReader::class)->institutionMonthlySales(
            CarbonImmutable::parse('2026-08-01'),
            CarbonImmutable::parse('2026-08-31'),
            $this->institutionA->id,
        ));
        try {
            app(InstitutionMonthlySalesService::class)->summary('2026-08', $this->institutionB->id);
            $this->fail('An institution outside the current business scope must be rejected.');
        } catch (\DomainException $exception) {
            $this->assertSame('所选机构不在当前权限范围内或已停用。', $exception->getMessage());
        }

        $this->get(route('reports.institution-sales'))->assertOk()->assertSee('机构销售额');
        $this->get(route('reports.institution-sales'))->assertDontSee('9,000,000');
        $this->get(route('reports.institution-sales'))->assertSee('1,000,000');
    }

    public function test_customer_service_cannot_open_or_export_institution_sales(): void
    {
        $customerService = User::factory()->create(['role' => UserRole::CustomerService]);

        $this->actingAs($customerService)->get(route('reports.institution-sales'))->assertForbidden();
        $this->get(route('reports.institution-sales.show', ['institution' => $this->institutionA->id]))->assertForbidden();
        $this->actingAs($customerService);
        $this->expectException(HttpException::class);
        app(ReportExportManager::class)->startInstitutionMonthlySales($customerService, '2026-08');
    }

    public function test_page_filter_and_xlsx_export_use_the_same_summary_values(): void
    {
        $customerA = $this->customer('导出客户 A');
        $customerB = $this->customer('导出客户 B');
        $this->order($customerA, $this->institutionA, 1_200_000, '2026-08-08', 'completed', 'active');
        $this->order($customerB, $this->institutionB, 800_000, '2026-08-09', 'completed', 'active');

        $this->actingAs($this->admin);
        Livewire::test(InstitutionMonthlySales::class)
            ->set('month', '2026-08')
            ->set('institutionId', (string) $this->institutionA->id)
            ->assertSee('1,200,000')
            ->assertDontSee('800,000');

        Storage::fake('local');
        $export = app(ReportExportManager::class)->startInstitutionMonthlySales($this->admin, '2026-08', $this->institutionA->id);
        $this->assertSame('completed', $export->status);
        $this->assertSame($this->institutionA->id, $export->criteria_snapshot['institution_id']);
        $this->assertSame($this->institutionA->name, $export->criteria_snapshot['institution_name']);
        $this->assertSame(1_200_000, $export->data_snapshot['total_amount_krw']);
        $this->assertSame(1, count($export->data_snapshot['rows']));
        Storage::disk('local')->assertExists($export->path);

        $workbook = IOFactory::load(Storage::disk('local')->path($export->path));
        $sheet = $workbook->getActiveSheet();
        $this->assertSame(1_200_000, $sheet->getCell('E5')->getValue());
        $this->assertSame('n', $sheet->getCell('E5')->getDataType());
        $this->assertSame(1_200_000, $sheet->getCell('E6')->getValue());
        $workbook->disconnectWorksheets();

        $pdfExport = app(ReportExportManager::class)->startInstitutionMonthlySales($this->admin, '2026-08', $this->institutionA->id, 'pdf');
        $this->assertSame('pdf', $pdfExport->format);
        $this->assertSame($export->data_snapshot, $pdfExport->data_snapshot);
        $this->assertStringEndsWith('.pdf', (string) $pdfExport->path);
        $this->assertStringStartsWith('%PDF-', Storage::disk('local')->get($pdfExport->path));

        $pdfPath = tempnam(sys_get_temp_dir(), 'gn-institution-sales-pdf-');
        $this->assertNotFalse($pdfPath);
        try {
            file_put_contents($pdfPath, Storage::disk('local')->get($pdfExport->path));
            $process = new Process(['pdftotext', $pdfPath, '-']);
            $process->run();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $this->assertStringContainsString('机构销售额', $process->getOutput());
            $this->assertStringContainsString('1,200,000', $process->getOutput());
        } finally {
            if (is_string($pdfPath) && is_file($pdfPath)) {
                unlink($pdfPath);
            }
        }

        $this->get(route('reports.exports.download', ['export' => $export]))
            ->assertOk()
            ->assertHeader('content-disposition');
        $this->get(route('reports.exports.download', ['export' => $pdfExport]))
            ->assertOk()
            ->assertHeader('content-disposition');
    }

    public function test_single_institution_pdf_contains_customer_order_and_item_details(): void
    {
        $customer = $this->customer('PDF 明细客户');
        $order = $this->order($customer, $this->institutionA, 900_000, '2026-08-08', 'completed', 'active');
        $order->items()->delete();
        $order->items()->createMany([
            [
                'project_snapshot' => '水光针',
                'quantity' => '1',
                'unit_price_krw' => 500_000,
                'amount_krw' => 500_000,
                'notes' => '首项目备注',
            ],
            [
                'project_snapshot' => 'Botox',
                'quantity' => '2',
                'unit_price_krw' => 200_000,
                'amount_krw' => 400_000,
                'notes' => '第二项目备注',
            ],
        ]);

        $this->actingAs($this->admin);
        Storage::fake('local');
        $export = app(ReportExportManager::class)->startInstitutionMonthlySales($this->admin, '2026-08', $this->institutionA->id, 'pdf');
        $pdfPath = tempnam(sys_get_temp_dir(), 'gn-institution-sales-detail-pdf-');
        $this->assertNotFalse($pdfPath);
        try {
            file_put_contents($pdfPath, Storage::disk('local')->get($export->path));
            $process = new Process(['pdftotext', $pdfPath, '-']);
            $process->run();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $text = $process->getOutput();
            $this->assertStringContainsString('机构', $text);
            $this->assertStringContainsString('PDF 明细客户', $text);
            $this->assertStringContainsString('#'.$order->id, $text);
            $this->assertStringContainsString('水光针', $text);
            $this->assertStringContainsString('Botox', $text);
            $this->assertStringContainsString('500,000', $text);
            $this->assertStringContainsString('400,000', $text);
            $this->assertStringNotContainsString('机构筛选', $text);
            $this->assertStringNotContainsString('对象', $text);
        } finally {
            if (is_string($pdfPath) && is_file($pdfPath)) {
                unlink($pdfPath);
            }
        }
    }

    public function test_all_institution_pdf_is_grouped_and_contains_each_institution(): void
    {
        $customerA = $this->customer('全部机构客户 A');
        $customerB = $this->customer('全部机构客户 B');
        $this->order($customerA, $this->institutionA, 1_000_000, '2026-08-08', 'completed', 'active');
        $this->order($customerB, $this->institutionB, 2_000_000, '2026-08-09', 'completed', 'active');

        $this->actingAs($this->admin);
        Storage::fake('local');
        $export = app(ReportExportManager::class)->startInstitutionMonthlySales($this->admin, '2026-08', null, 'pdf');
        $pdfPath = tempnam(sys_get_temp_dir(), 'gn-institution-sales-all-pdf-');
        $this->assertNotFalse($pdfPath);
        try {
            file_put_contents($pdfPath, Storage::disk('local')->get($export->path));
            $process = new Process(['pdftotext', $pdfPath, '-']);
            $process->run();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $text = $process->getOutput();
            $this->assertStringContainsString('全部机构', $text);
            $this->assertStringContainsString($this->institutionA->name, $text);
            $this->assertStringContainsString($this->institutionB->name, $text);
            $this->assertStringContainsString('全部机构客户 A', $text);
            $this->assertStringContainsString('全部机构客户 B', $text);
            $this->assertStringContainsString('3,000,000', $text);
            $this->assertStringNotContainsString('机构筛选', $text);
            $this->assertStringNotContainsString('对象', $text);
        } finally {
            if (is_string($pdfPath) && is_file($pdfPath)) {
                unlink($pdfPath);
            }
        }
    }

    private function customer(string $name, ?Agent $agent = null): Customer
    {
        return Customer::query()->create([
            'code' => 'SALES-CUST-'.uniqid(),
            'name' => $name,
            'source_agent_id' => $agent?->id ?? $this->agent->id,
            'current_status_id' => CustomerStatus::query()->where('key', 'booked')->value('id'),
        ]);
    }

    private function order(
        Customer $customer,
        Institution $institution,
        int $amount,
        ?string $occurredOn,
        string $status,
        string $recordStatus,
        ?string $completedAt = null,
        ?Agent $agent = null,
    ): Order {
        $order = Order::query()->create([
            'customer_id' => $customer->id,
            'institution_id' => $institution->id,
            'agent_id' => ($agent ?? $this->agent)->id,
            'project_name' => '机构销售额测试项目',
            'amount_krw' => $amount,
            'completed_on' => $occurredOn,
            'occurred_on' => $occurredOn,
            'completed_at' => $completedAt ?? ($occurredOn === null ? null : $occurredOn.' 10:00:00'),
            'completion_precision' => 'date',
            'status' => $status,
            'record_status' => $recordStatus,
        ]);
        $order->items()->create([
            'project_snapshot' => '机构销售额测试项目',
            'quantity' => '1',
            'unit_price_krw' => $amount,
            'amount_krw' => $amount,
            'notes' => null,
        ]);

        return $order;
    }
}
