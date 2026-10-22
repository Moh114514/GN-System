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
use App\Modules\Order\Application\Data\CompletedOrderItemData;
use App\Modules\Order\Application\Data\CompletedOrderRegistrationData;
use App\Modules\Order\Application\Data\InstitutionReturnUploadData;
use App\Modules\Order\Application\Data\OrderEvidenceUploadData;
use App\Modules\Order\Application\Services\CompletedOrderRegistrar;
use App\Modules\Order\Application\Services\CustomerOrderRegistrationWorkspace;
use App\Modules\Order\Application\Services\InstitutionFormTemplateService;
use App\Modules\Order\Application\Services\InstitutionReturnParser;
use App\Modules\Order\Application\Services\InstitutionReturnProcessor;
use App\Modules\Order\Infrastructure\Models\Appointment;
use App\Modules\Order\Infrastructure\Models\InstitutionReturnFile;
use App\Modules\Order\Infrastructure\Models\Order;
use App\Modules\Order\Infrastructure\Models\OrderEvidenceFile;
use App\Modules\Order\Infrastructure\Models\OrderItem;
use App\Modules\Order\Infrastructure\Models\OrderRegistrationDraft;
use App\Modules\Order\Presentation\Livewire\CustomerOrderRegistration;
use App\Modules\Order\Presentation\Livewire\InstitutionReturnCenter;
use App\Modules\Settlement\Application\Contracts\DailyCommissionGateway;
use App\Modules\Settlement\Infrastructure\Models\CommissionRule;
use Database\Seeders\PhaseTwoReferenceDataSeeder;
use DateTimeImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Mockery;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $this->arrive($customer, $user);
        $appointment = Appointment::query()->where('customer_id', $customer->id)->sole();
        $occurredOn = $customer->refresh()->arrived_at?->toDateString();
        $this->assertNotNull($occurredOn);
        $contents = $this->workbook($institution->id, $customer->id, $occurredOn, 2, 150000, 300000);

        $orderId = app(InstitutionReturnProcessor::class)->upload(new InstitutionReturnUploadData(
            institutionId: $institution->id,
            customerId: $customer->id,
            originalName: '机构回传.xlsx',
            extension: 'xlsx',
            mimeType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            contents: $contents,
            actorId: $user->id,
            ipAddress: '127.0.0.1',
            evidence: $this->evidence(),
        ));

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'customer_id' => $customer->id,
            'institution_id' => $institution->id,
            'agent_id' => $agent->id,
            'occurred_on' => $occurredOn,
            'completed_on' => $occurredOn,
            'status' => 'completed',
            'record_status' => 'active',
        ]);
        $this->assertSame(1, OrderItem::query()->where('order_id', $orderId)->count());
        $this->assertDatabaseHas('order_commissions', ['order_id' => $orderId, 'amount_krw' => 30000]);
        $this->assertDatabaseCount('reminders', 0);
        $this->assertDatabaseHas('institution_return_files', ['status' => 'processed', 'original_name' => '机构回传.xlsx']);
        $this->assertDatabaseHas('customer_statuses', ['key' => 'treatment_completed']);
        $this->assertSame('treatment_completed', $customer->refresh()->currentStatus?->key);
        $this->assertNotNull($customer->treatment_completed_at);
        $this->assertSame('completed', $appointment->refresh()->status);
    }

    public function test_customer_registration_upload_shows_success_and_dispatches_refresh_event(): void
    {
        $user = User::factory()->create();
        $institution = Institution::query()->firstOrFail();
        $agent = $this->agent($institution);
        $customer = $this->customer($agent, $user);
        $arrived = CustomerStatus::query()->where('key', 'arrived')->firstOrFail();
        app(CustomerStatusManager::class)->change($customer->id, $arrived->id, '客户已到院', $user, null);
        $contents = $this->workbook($institution->id, $customer->id, $customer->refresh()->arrived_at?->toDateString(), 1, 200000, 200000);

        Livewire::actingAs($user)
            ->test(CustomerOrderRegistration::class, ['customerId' => $customer->id])
            ->set('institutionId', (string) $institution->id)
            ->set('upload', UploadedFile::fake()->createWithContent('登记订单.xlsx', $contents))
            ->set('communicationScreenshots', [UploadedFile::fake()->image('沟通.png')])
            ->set('settlementReceipts', [UploadedFile::fake()->image('小票.png')])
            ->call('uploadReturn')
            ->assertHasNoErrors()
            ->assertSet('status', 'success')
            ->assertSet('successResult.project_name', '皮肤管理')
            ->assertDispatched('customer-order-registered');

        $this->assertDatabaseCount('orders', 1);
    }

    public function test_customer_registration_excel_upload_requires_at_least_one_evidence_file(): void
    {
        $user = User::factory()->create();
        $institution = Institution::query()->firstOrFail();
        $agent = $this->agent($institution);
        $customer = $this->customer($agent, $user);
        $this->arrive($customer, $user);
        $contents = $this->workbook($institution->id, $customer->id, $customer->refresh()->arrived_at?->toDateString(), 1, 200000, 200000);

        Livewire::actingAs($user)
            ->test(CustomerOrderRegistration::class, ['customerId' => $customer->id])
            ->set('institutionId', (string) $institution->id)
            ->set('upload', UploadedFile::fake()->createWithContent('缺凭证.xlsx', $contents))
            ->call('uploadReturn')
            ->assertHasErrors(['communicationScreenshots', 'settlementReceipts']);

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_institution_return_center_excel_upload_requires_at_least_one_evidence_file(): void
    {
        $user = User::factory()->create();
        $institution = Institution::query()->firstOrFail();
        $agent = $this->agent($institution);
        $customer = $this->customer($agent, $user);
        $this->arrive($customer, $user);
        $contents = $this->workbook($institution->id, $customer->id, $customer->refresh()->arrived_at?->toDateString(), 1, 200000, 200000);

        Livewire::actingAs($user)
            ->test(InstitutionReturnCenter::class)
            ->set('institutionId', (string) $institution->id)
            ->set('customerId', (string) $customer->id)
            ->set('upload', UploadedFile::fake()->createWithContent('中心缺凭证.xlsx', $contents))
            ->call('uploadReturn')
            ->assertHasErrors(['communicationScreenshots', 'settlementReceipts']);

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_institution_template_v2_hides_legacy_fields_and_prefills_arrival_date(): void
    {
        $user = User::factory()->create();
        $institution = Institution::query()->firstOrFail();
        $agent = $this->agent($institution);
        $customer = $this->customer($agent, $user);
        $this->arrive($customer, $user);
        $customer->refresh();

        $generated = app(InstitutionFormTemplateService::class)->generate($institution->id, $customer->id);
        $spreadsheet = IOFactory::load($generated['path']);
        $sheet = $spreadsheet->getSheetByName('机构回传');
        $metadata = $spreadsheet->getSheetByName('__GN_META');
        $metadataKeys = [];
        for ($row = 2; $row <= $metadata->getHighestRow(); $row++) {
            $metadataKeys[(string) $metadata->getCell("A{$row}")->getValue()] = (string) $metadata->getCell("B{$row}")->getValue();
        }

        $this->assertSame(2, $generated['metadata']['template_version']);
        $this->assertSame(['客户姓名', '消费日期', '项目', '数量', '金额（KRW）', '业务备注'], [
            $sheet->getCell('A1')->getValue(),
            $sheet->getCell('B1')->getValue(),
            $sheet->getCell('C1')->getValue(),
            $sheet->getCell('D1')->getValue(),
            $sheet->getCell('E1')->getValue(),
            $sheet->getCell('F1')->getValue(),
        ]);
        $this->assertSame('客户姓名', $sheet->getCell('A1')->getValue());
        $this->assertSame($customer->name, $sheet->getCell('A2')->getValue());
        $this->assertSame($customer->arrived_at?->toDateString(), substr((string) $sheet->getCell('B2')->getValue(), 0, 10));
        $this->assertSame(1, $sheet->getCell('D2')->getValue());
        $this->assertSame($customer->code, $metadataKeys['customer_code']);
        $this->assertSame((string) $customer->id, $metadataKeys['customer_id']);
        $this->assertSame(Worksheet::SHEETSTATE_VERYHIDDEN, $metadata->getSheetState());

        @unlink($generated['path']);
    }

    public function test_excel_date_must_match_customer_arrival_date(): void
    {
        $user = User::factory()->create();
        $institution = Institution::query()->firstOrFail();
        $agent = $this->agent($institution);
        $customer = $this->customer($agent, $user);
        $this->arrive($customer, $user);
        $wrongDate = $customer->refresh()->arrived_at?->addDay()->toDateString();
        $contents = $this->workbook($institution->id, $customer->id, $wrongDate, 1, 100000, 100000);

        $this->expectException(DomainException::class);
        app(InstitutionReturnProcessor::class)->upload(new InstitutionReturnUploadData(
            institutionId: $institution->id,
            customerId: $customer->id,
            originalName: '日期不一致.xlsx',
            extension: 'xlsx',
            mimeType: null,
            contents: $contents,
            actorId: $user->id,
            ipAddress: null,
            evidence: $this->evidence(),
        ));

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_manual_registration_creates_one_order_with_multiple_items_and_private_evidence(): void
    {
        $user = User::factory()->create();
        $institution = Institution::query()->firstOrFail();
        $agent = $this->agent($institution);
        $customer = $this->customer($agent, $user);
        $arrived = CustomerStatus::query()->where('key', 'arrived')->firstOrFail();
        app(CustomerStatusManager::class)->change($customer->id, $arrived->id, '客户已到院', $user, null);
        $arrivedOn = $customer->refresh()->arrived_at?->toDateString();

        Livewire::actingAs($user)
            ->test(CustomerOrderRegistration::class, ['customerId' => $customer->id])
            ->set('institutionId', (string) $institution->id)
            ->set('items', [
                ['project_name' => '水光针', 'amount_krw' => '500000'],
                ['project_name' => 'Botox', 'amount_krw' => '300000'],
                ['project_name' => '皮肤管理', 'amount_krw' => '200000'],
            ])
            ->set('communicationScreenshots', [UploadedFile::fake()->image('沟通.png')])
            ->set('settlementReceipts', [UploadedFile::fake()->image('小票.png')])
            ->call('registerOrder')
            ->assertHasNoErrors()
            ->assertSet('status', 'success')
            ->assertSet('successResult.item_count', 3)
            ->assertDispatched('customer-order-registered');

        $order = Order::query()->firstOrFail();
        $this->assertSame($arrivedOn, $order->occurred_on?->toDateString());
        $this->assertSame(1000000, (int) $order->amount_krw);
        $this->assertSame(3, OrderItem::query()->where('order_id', $order->id)->count());
        $this->assertSame(1000000, (int) OrderItem::query()->where('order_id', $order->id)->sum('amount_krw'));
        $this->assertDatabaseCount('order_evidence_files', 2);
        $this->assertDatabaseHas('order_evidence_files', ['order_id' => $order->id, 'type' => 'communication_screenshot']);
        $this->assertDatabaseHas('order_evidence_files', ['order_id' => $order->id, 'type' => 'settlement_receipt']);

        $evidence = OrderEvidenceFile::query()->where('order_id', $order->id)->firstOrFail();
        Storage::disk('local')->assertExists($evidence->encrypted_path);
        $this->actingAs($user)
            ->get(route('orders.evidence.download', $evidence->id))
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="沟通.png"');
        $this->actingAs(User::factory()->create())
            ->get(route('orders.evidence.download', $evidence->id))
            ->assertNotFound();
    }

    public function test_manual_registration_requires_at_least_one_evidence_file(): void
    {
        $user = User::factory()->create();
        $institution = Institution::query()->firstOrFail();
        $agent = $this->agent($institution);
        $customer = $this->customer($agent, $user);
        $arrived = CustomerStatus::query()->where('key', 'arrived')->firstOrFail();
        app(CustomerStatusManager::class)->change($customer->id, $arrived->id, '客户已到院', $user, null);

        Livewire::actingAs($user)
            ->test(CustomerOrderRegistration::class, ['customerId' => $customer->id])
            ->set('institutionId', (string) $institution->id)
            ->set('items', [['project_name' => '水光针', 'amount_krw' => '500000']])
            ->call('registerOrder')
            ->assertHasErrors(['communicationScreenshots', 'settlementReceipts']);

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_evidence_files', 0);
    }

    public function test_manual_registration_draft_restores_and_submission_key_prevents_duplicate_orders(): void
    {
        $user = User::factory()->create();
        $institution = Institution::query()->firstOrFail();
        $agent = $this->agent($institution);
        $customer = $this->customer($agent, $user);
        $submissionKey = (string) Str::uuid();
        $items = [
            ['project_name' => '草稿项目甲', 'amount_krw' => '120000'],
            ['project_name' => '草稿项目乙', 'amount_krw' => '80000'],
        ];
        $evidenceFile = UploadedFile::fake()->image('草稿凭证.png');

        Livewire::actingAs($user)->test(CustomerOrderRegistration::class, ['customerId' => $customer->id])
            ->set('submissionKey', $submissionKey)
            ->set('institutionId', (string) $institution->id)
            ->set('items', $items)
            ->call('saveDraft')
            ->assertHasNoErrors()
            ->assertSet('status', 'draft_saved')
            ->assertSet('draftId', $submissionKey);

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame($items, Livewire::actingAs($user)
            ->test(CustomerOrderRegistration::class, ['customerId' => $customer->id])
            ->get('items'));

        $this->arrive($customer, $user);
        $appointment = Appointment::query()->where('customer_id', $customer->id)->firstOrFail();

        Livewire::actingAs($user)->test(CustomerOrderRegistration::class, ['customerId' => $customer->id])
            ->set('communicationScreenshots', [$evidenceFile])
            ->call('registerOrder')
            ->assertHasNoErrors()
            ->assertSet('status', 'success')
            ->assertSet('draftId', null)
            ->assertDontSee(__('orders.registration.draft_saved'));

        $draft = OrderRegistrationDraft::query()->findOrFail($submissionKey);
        $this->assertNotNull($draft->completed_at);
        $this->assertSame((int) $appointment->id, (int) $draft->appointment_id);
        $this->assertSame(2, OrderItem::query()->where('order_id', $draft->completed_order_id)->count());

        $duplicateId = app(CompletedOrderRegistrar::class)->register(new CompletedOrderRegistrationData(
            customerId: $customer->id,
            institutionId: $institution->id,
            agentId: $agent->id,
            items: [
                new CompletedOrderItemData('草稿项目甲', 120000),
                new CompletedOrderItemData('草稿项目乙', 80000),
            ],
            occurredOn: $appointment->arrived_at,
            actorId: $user->id,
            ipAddress: null,
            ownerId: $user->id,
            source: 'manual_registration',
            evidence: [new OrderEvidenceUploadData(
                'communication_screenshot',
                $evidenceFile->getClientOriginalName(),
                $evidenceFile->getMimeType(),
                $evidenceFile->get(),
            )],
            appointmentId: $appointment->id,
            idempotencyKey: $submissionKey,
        ));
        $this->assertSame((int) $draft->completed_order_id, $duplicateId);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_manual_registration_keeps_order_institution_bound_to_the_arrival_visit(): void
    {
        $user = User::factory()->create();
        $institution = Institution::query()->firstOrFail();
        $agent = $this->agent($institution);
        $customer = $this->customer($agent, $user);
        $this->arrive($customer, $user);
        $otherInstitution = Institution::query()->create([
            'code' => 'TEST-OTHER-RETURN',
            'name' => '其他到院机构',
            'is_active' => true,
        ]);

        Livewire::actingAs($user)->test(CustomerOrderRegistration::class, ['customerId' => $customer->id])
            ->assertDontSee(__('orders.registration.change_institution'))
            ->set('institutionId', (string) $otherInstitution->id)
            ->set('items', [['project_name' => '到院关联项目', 'amount_krw' => '100000']])
            ->set('communicationScreenshots', [UploadedFile::fake()->image('visit-evidence.png')])
            ->call('registerOrder')
            ->assertSet('status', 'error')
            ->assertSet('errorMessage', __('orders.errors.visit_institution_mismatch'));

        $this->assertDatabaseCount('orders', 0);
    }

    #[DataProvider('validEvidenceCombinations')]
    public function test_registration_accepts_either_evidence_type_and_preserves_all_files(string $entry, int $screenshots, int $receipts): void
    {
        $user = User::factory()->create();
        $institution = Institution::query()->firstOrFail();
        $agent = $this->agent($institution);
        $customer = $this->customer($agent, $user);
        $this->arrive($customer, $user);
        $communicationFiles = array_map(fn (int $index) => UploadedFile::fake()->image("communication-{$index}.png", 10 + $index, 10), range(1, max(1, $screenshots)));
        $receiptFiles = array_map(fn (int $index) => UploadedFile::fake()->image("receipt-{$index}.png", 10 + $index, 20), range(1, max(1, $receipts)));

        $component = $this->registrationEntry($entry, $user, $institution, $customer)
            ->set('communicationScreenshots', $screenshots === 0 ? [] : $communicationFiles)
            ->set('settlementReceipts', $receipts === 0 ? [] : $receiptFiles);
        // Neither file picker may block submission when only the other type is supplied.
        foreach (['communicationScreenshots', 'settlementReceipts'] as $field) {
            $this->assertDoesNotMatchRegularExpression('/<input\b(?=[^>]*wire:model="'.$field.'")[^>]*\brequired(?:\s|=|>)/', $component->html());
        }
        $component->call($entry === 'manual' ? 'registerOrder' : 'uploadReturn')->assertHasNoErrors();
        if ($entry !== 'center') {
            $component->assertSet('status', 'success');
        }

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_evidence_files', $screenshots + $receipts);
        $this->assertSame($screenshots, OrderEvidenceFile::query()->where('type', 'communication_screenshot')->count());
        $this->assertSame($receipts, OrderEvidenceFile::query()->where('type', 'settlement_receipt')->count());
        foreach (OrderEvidenceFile::query()->get() as $file) {
            Storage::disk('local')->assertExists($file->encrypted_path);
        }
    }

    /** @return iterable<string, array{string, int, int}> */
    public static function validEvidenceCombinations(): iterable
    {
        foreach (['manual', 'excel', 'center'] as $entry) {
            foreach ([[1, 0], [0, 1], [1, 1], [2, 2]] as [$screenshots, $receipts]) {
                yield "{$entry}-{$screenshots}-{$receipts}" => [$entry, $screenshots, $receipts];
            }
        }
    }

    #[DataProvider('invalidEvidenceFiles')]
    public function test_registration_rejects_invalid_evidence_even_when_the_other_type_is_valid(string $entry, string $field, string $invalidKind): void
    {
        config(['livewire.temporary_file_upload.rules' => ['required', 'file', 'max:30720']]);
        $user = User::factory()->create();
        $institution = Institution::query()->firstOrFail();
        $customer = $this->customer($this->agent($institution), $user);
        $this->arrive($customer, $user);
        $invalid = $invalidKind === 'size'
            ? UploadedFile::fake()->create('oversized.pdf', 20481, 'application/pdf')
            : UploadedFile::fake()->createWithContent('invalid.txt', 'not an image or PDF');
        $otherField = $field === 'communicationScreenshots' ? 'settlementReceipts' : 'communicationScreenshots';

        $this->registrationEntry($entry, $user, $institution, $customer)
            ->set($otherField, [UploadedFile::fake()->image('valid.png')])
            ->set($field, [$invalid])
            ->call($entry === 'manual' ? 'registerOrder' : 'uploadReturn')
            ->assertHasErrors([$field.'.0' => $invalidKind === 'size' ? 'max' : 'mimes']);

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_evidence_files', 0);
        $this->assertDatabaseCount('institution_return_files', 0);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function invalidEvidenceFiles(): iterable
    {
        foreach (['manual', 'excel', 'center'] as $entry) {
            foreach (['communicationScreenshots', 'settlementReceipts'] as $field) {
                foreach (['type', 'size'] as $kind) {
                    yield "{$entry}-{$field}-{$kind}" => [$entry, $field, $kind];
                }
            }
        }
    }

    public function test_registrar_rejects_empty_evidence_without_the_livewire_form(): void
    {
        $user = User::factory()->create();
        $institution = Institution::query()->firstOrFail();
        $agent = $this->agent($institution);
        $customer = $this->customer($agent, $user);
        $this->arrive($customer, $user);
        $this->actingAs($user);

        try {
            app(CompletedOrderRegistrar::class)->register(new CompletedOrderRegistrationData(
                customerId: $customer->id,
                institutionId: $institution->id,
                agentId: $agent->id,
                items: [new CompletedOrderItemData('皮肤管理', 200000)],
                occurredOn: $customer->refresh()->arrived_at,
                actorId: $user->id,
                ipAddress: null,
                ownerId: $user->id,
                source: 'manual_registration',
                requireArrived: true,
                requireEvidence: true,
            ));
            $this->fail('An order without evidence must be rejected.');
        } catch (DomainException $exception) {
            $this->assertSame(__('orders.errors.order_evidence_required'), $exception->getMessage());
        }

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_evidence_files', 0);
        $this->assertNotSame('treatment_completed', $customer->refresh()->currentStatus?->key);
    }

    public function test_registering_an_unassigned_agent_customer_does_not_make_the_actor_its_owner(): void
    {
        $user = User::factory()->superAdmin()->withTwoFactor()->create();
        $institution = Institution::query()->firstOrFail();
        $customer = $this->customer($this->agent($institution), $user);
        $this->arrive($customer, $user);
        $customer->update(['owner_id' => null]);

        Livewire::actingAs($user)->test(CustomerOrderRegistration::class, ['customerId' => $customer->id])
            ->set('institutionId', (string) $institution->id)
            ->set('items', [['project_name' => 'Treatment', 'amount_krw' => '100000']])
            ->set('communicationScreenshots', [UploadedFile::fake()->image('evidence.png')])
            ->call('registerOrder')->assertHasNoErrors()->assertSet('status', 'success');

        $this->assertDatabaseHas('orders', ['customer_id' => $customer->id, 'owner_id' => null, 'status' => 'completed']);
        $this->assertNull($customer->refresh()->owner_id);
        $this->assertDatabaseHas('order_commissions', ['order_id' => Order::query()->sole()->id, 'amount_krw' => 10000]);
    }

    public function test_manual_registration_is_rejected_for_customer_who_has_not_arrived(): void
    {
        $user = User::factory()->create();
        $institution = Institution::query()->firstOrFail();
        $agent = $this->agent($institution);
        $customer = $this->customer($agent, $user);

        Livewire::actingAs($user)
            ->test(CustomerOrderRegistration::class, ['customerId' => $customer->id])
            ->set('institutionId', (string) $institution->id)
            ->set('items', [['project_name' => '水光针', 'amount_krw' => '500000']])
            ->set('communicationScreenshots', [UploadedFile::fake()->image('沟通.png')])
            ->set('settlementReceipts', [UploadedFile::fake()->image('小票.png')])
            ->call('registerOrder')
            ->assertSee(__('orders.errors.customer_not_arrived'))
            ->assertSet('status', 'error');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_unavailable_customer_registration_actions_are_visibly_disabled(): void
    {
        $user = User::factory()->create();
        $institution = Institution::query()->firstOrFail();
        $agent = $this->agent($institution);
        $customer = $this->customer($agent, $user);

        $component = Livewire::actingAs($user)
            ->test(CustomerOrderRegistration::class, ['customerId' => $customer->id]);

        $component
            ->assertSee(__('orders.registration.unavailable_hint'))
            ->assertSee('cursor-not-allowed', false)
            ->assertSee('opacity-50', false)
            ->assertSee('disabled', false);
        $this->assertSame(2, substr_count($component->html(), __('orders.registration.unavailable_hint')));
    }

    public function test_amount_mismatch_is_rejected_without_an_order(): void
    {
        $user = User::factory()->create();
        $institution = Institution::query()->firstOrFail();
        $agent = $this->agent($institution);
        $customer = $this->customer($agent, $user);
        $this->arrive($customer, $user);
        $contents = $this->workbook($institution->id, $customer->id, $customer->refresh()->arrived_at?->toDateString(), 3, 0, 100);

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
            evidence: $this->evidence(),
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
        $this->arrive($customer, $user);
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
            evidence: $this->evidence(),
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
        $this->arrive($customer, $user);
        $contents = $this->workbook($institution->id, $customer->id, $customer->refresh()->arrived_at?->toDateString(), 1, 200000, 200000);
        $data = new InstitutionReturnUploadData($institution->id, $customer->id, '重复.xlsx', 'xlsx', null, $contents, $user->id, null, $this->evidence());

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
        $this->arrive($customer, $user);
        $contents = $this->workbook($institution->id, $customer->id, $customer->refresh()->arrived_at?->toDateString(), 1, 100000, 100000);
        $gateway = Mockery::mock(DailyCommissionGateway::class);
        $gateway->shouldReceive('recordForCompletedOrder')->once()->andThrow(new DomainException('commission failure'));
        app()->instance(DailyCommissionGateway::class, $gateway);

        $this->expectException(DomainException::class);
        app(InstitutionReturnProcessor::class)->upload(new InstitutionReturnUploadData($institution->id, $customer->id, '失败.xlsx', 'xlsx', null, $contents, $user->id, null, $this->evidence()));

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
        $this->arrive($customer, $user);
        $contents = $this->workbook($institution->id, $customer->id, $customer->refresh()->arrived_at?->toDateString(), 1, 100000, 100000);
        $orderId = app(InstitutionReturnProcessor::class)->upload(new InstitutionReturnUploadData($institution->id, $customer->id, '原始回传.xlsx', 'xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $contents, $user->id, null, $this->evidence()));
        $file = InstitutionReturnFile::query()->firstOrFail();

        $response = $this->actingAs($user)->get(route('institution-returns.download', $file->id));

        $response->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="原始回传.xlsx"');
        $this->assertSame($contents, $response->getContent());
        $this->assertSame($orderId, Order::query()->value('id'));
    }

    private function registrationEntry(string $entry, User $user, Institution $institution, Customer $customer): Testable
    {
        $component = $entry === 'center'
            ? Livewire::actingAs($user)->test(InstitutionReturnCenter::class)->set('customerId', (string) $customer->id)
            : Livewire::actingAs($user)->test(CustomerOrderRegistration::class, ['customerId' => $customer->id]);
        $component->set('institutionId', (string) $institution->id);
        if ($entry === 'manual') {
            return $component->set('items', [['project_name' => '皮肤管理', 'amount_krw' => '200000']]);
        }

        $contents = $this->workbook($institution->id, $customer->id, $customer->refresh()->arrived_at?->toDateString(), 1, 200000, 200000);

        return $component->set('upload', UploadedFile::fake()->createWithContent('return.xlsx', $contents));
    }

    private function workbook(int $institutionId, int $customerId, mixed $date, int $quantity, int $unitPrice, int $amount): string
    {
        $generated = app(InstitutionFormTemplateService::class)->generate($institutionId, $customerId);
        $spreadsheet = IOFactory::load($generated['path']);
        $sheet = $spreadsheet->getSheetByName('机构回传');
        $sheet->fromArray([
            $sheet->getCell('A2')->getValue(),
            $date,
            '皮肤管理',
            $quantity,
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

    /** @return array<int, OrderEvidenceUploadData> */
    private function evidence(): array
    {
        return [
            new OrderEvidenceUploadData('communication_screenshot', '沟通.png', 'image/png', 'communication'),
            new OrderEvidenceUploadData('settlement_receipt', '小票.png', 'image/png', 'receipt'),
        ];
    }

    public function test_legacy_arrival_is_previewed_then_auditably_restored_for_order_registration(): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->superAdmin()->create();
        $institution = Institution::query()->firstOrFail();
        $customer = $this->customer($this->agent($institution), $user);
        $this->arrive($customer, $user);
        $appointment = Appointment::query()->where('customer_id', $customer->id)->sole();
        $verifiedTime = $appointment->arrived_at->format('Y-m-d H:i:s');
        $customerBefore = $customer->fresh()->getAttributes();
        $appointment->update(['arrived_at' => null]);
        $this->assertFalse(app(CustomerOrderRegistrationWorkspace::class)->context($customer->id)['can_register']);
        $arguments = ['appointment' => $appointment->id, '--arrived-at' => $verifiedTime];
        $this->assertSame(0, Artisan::call('app:backfill-appointment-arrival', $arguments));
        $this->assertNull($appointment->fresh()->arrived_at);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseMissing('activity_log', ['event' => 'legacy_arrival_backfilled']);
        $this->assertSame(0, Artisan::call('app:business-anomalies'));
        $this->assertStringContainsString('已到院记录缺少实际到院时间', Artisan::output());

        $this->assertSame(0, Artisan::call('app:backfill-appointment-arrival', [
            ...$arguments, '--apply' => true, '--actor' => $admin->id, '--reason' => '管理员核对原始到院凭据',
        ]));
        $this->assertSame($verifiedTime, $appointment->fresh()->arrived_at->format('Y-m-d H:i:s'));
        $this->assertSame($customerBefore, $customer->fresh()->getAttributes());
        $this->assertDatabaseHas('activity_log', ['event' => 'legacy_arrival_backfilled', 'subject_id' => $appointment->id, 'causer_id' => $admin->id]);
        $this->assertDatabaseCount('orders', 0);
        Livewire::actingAs($user)->test(CustomerOrderRegistration::class, ['customerId' => $customer->id])
            ->set('items', [['project_name' => '历史到院补录订单', 'amount_krw' => '100000']])
            ->set('communicationScreenshots', [UploadedFile::fake()->image('凭证.png')])
            ->call('registerOrder')->assertHasNoErrors()->assertSet('status', 'success');
        $this->assertDatabaseHas('orders', ['appointment_id' => $appointment->id, 'amount_krw' => 100000]);
    }

    public function test_legacy_arrival_rejects_missing_evidence_unauthorized_actor_future_time_and_overwrite(): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->superAdmin()->create();
        $institution = Institution::query()->firstOrFail();
        $customer = $this->customer($this->agent($institution), $user);
        $this->arrive($customer, $user);
        $appointment = Appointment::query()->where('customer_id', $customer->id)->sole();
        $time = $appointment->arrived_at->format('Y-m-d H:i:s');
        $appointment->update(['arrived_at' => null]);
        $valid = ['appointment' => $appointment->id, '--arrived-at' => $time, '--apply' => true, '--actor' => $admin->id, '--reason' => '核对凭据'];
        foreach ([['--actor' => $user->id], ['--actor' => null], ['--reason' => ''], ['--arrived-at' => '2026-02-30 10:00:00'], ['--arrived-at' => '2099-01-01 10:00:00']] as $invalid) {
            $this->assertSame(1, Artisan::call('app:backfill-appointment-arrival', array_replace($valid, $invalid)));
            $this->assertNull($appointment->fresh()->arrived_at);
        }
        $this->assertDatabaseMissing('activity_log', ['event' => 'legacy_arrival_backfilled']);
        $appointment->update(['arrived_at' => $time]);
        $this->assertSame(1, Artisan::call('app:backfill-appointment-arrival', $valid));
        $this->assertSame($time, $appointment->fresh()->arrived_at->format('Y-m-d H:i:s'));
        $this->assertDatabaseCount('orders', 0);
    }

    private function arrive(Customer $customer, User $user): void
    {
        $arrived = CustomerStatus::query()->where('key', 'arrived')->firstOrFail();
        app(CustomerStatusManager::class)->change($customer->id, $arrived->id, '客户已到院', $user, null);
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

        $customer = Customer::query()->create([
            'code' => 'TEST-JG-PR4-0001',
            'name' => 'PR4 测试客户',
            'source_agent_id' => $agent->id,
            'owner_id' => $owner->id,
        ]);
        Appointment::query()->create([
            'customer_id' => $customer->id,
            'institution_id' => Institution::query()->firstOrFail()->id,
            'owner_id' => $owner->id,
            'status' => 'scheduled',
        ]);

        return $customer;
    }
}
