<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Agent\Infrastructure\Models\Agent;
use App\Modules\Agent\Infrastructure\Models\AgentBusinessGroupAssignment;
use App\Modules\Agent\Infrastructure\Models\AgentTypeCode;
use App\Modules\Auth\Infrastructure\Models\BusinessGroup;
use App\Modules\Auth\Infrastructure\Models\BusinessGroupMembership;
use App\Modules\Config\Infrastructure\Models\Institution;
use App\Modules\Customer\Application\Data\CustomerProfileData;
use App\Modules\Customer\Application\Services\CustomerProfileManager;
use App\Modules\Customer\Application\Services\CustomerTransferManager;
use App\Modules\Customer\Infrastructure\Models\Customer;
use App\Modules\Customer\Infrastructure\Models\CustomerContact;
use App\Modules\Customer\Infrastructure\Models\CustomerStatus;
use App\Modules\Customer\Infrastructure\Models\DirectCustomerChannel;
use App\Modules\Customer\Presentation\Livewire\CustomerForm;
use App\Modules\Customer\Presentation\Livewire\DirectCustomerForm;
use App\Modules\Order\Application\Data\DailyOrderData;
use App\Modules\Order\Application\Services\DatabaseDailyOrderGateway;
use App\Modules\Order\Application\Services\InstitutionFormTemplateService;
use App\Modules\Order\Infrastructure\Models\Order;
use App\Modules\Order\Presentation\Livewire\CustomerOrderRegistration;
use App\Modules\Order\Presentation\Livewire\InstitutionReturnCenter;
use App\Modules\Reminder\Application\Services\ReminderScheduler;
use App\Modules\Reminder\Infrastructure\Models\Reminder;
use App\Modules\Reminder\Infrastructure\Models\ReminderEvent;
use App\Modules\Reminder\Infrastructure\Models\ReminderRule;
use App\Modules\Settlement\Infrastructure\Models\DirectCommissionRate;
use Carbon\CarbonImmutable;
use Database\Seeders\PhaseTwoReferenceDataSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CustomerOptionalProfileTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $bd;

    private User $cs;

    private User $manager;

    private Agent $agent;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-15 10:00:00');
        Storage::fake('local');
        $this->seed(PhaseTwoReferenceDataSeeder::class);
        $this->admin = User::factory()->superAdmin()->withTwoFactor()->create();
        $this->bd = User::factory()->create(['role' => 'bd_manager']);
        $this->cs = User::factory()->create();
        $this->manager = User::factory()->create(['role' => 'direct_customer_manager']);
        $this->institution = Institution::query()->firstOrFail();
        $group = BusinessGroup::query()->create(['code' => 'OPTIONAL', 'name' => 'Optional profiles', 'is_active' => true, 'created_by' => $this->admin->id]);
        foreach ([[$this->bd, 'bd_manager'], [$this->cs, 'customer_service']] as [$user, $role]) {
            BusinessGroupMembership::query()->create([
                'business_group_id' => $group->id, 'user_id' => $user->id, 'member_role' => $role,
                'effective_from' => '2026-01-01', 'assigned_by' => $this->admin->id, 'reason' => 'Optional profile scope',
            ]);
        }
        $this->agent = Agent::query()->create([
            'agent_type_code_id' => AgentTypeCode::query()->firstOrFail()->id, 'code' => 'OPT',
            'name' => 'Optional agent', 'cooperation_status' => 'active',
        ]);
        AgentBusinessGroupAssignment::query()->create([
            'agent_id' => $this->agent->id, 'business_group_id' => $group->id,
            'effective_from' => '2026-01-01', 'assigned_by' => $this->admin->id, 'reason' => 'Optional profile scope',
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** @return iterable<string, array{string, string}> */
    public static function creationRoles(): iterable
    {
        yield 'admin-agent' => ['agent', 'admin'];
        yield 'bd-agent' => ['agent', 'bd'];
        yield 'cs-agent' => ['agent', 'cs'];
        yield 'admin-direct' => ['direct', 'admin'];
        yield 'manager-direct' => ['direct', 'manager'];
    }

    #[DataProvider('creationRoles')]
    public function test_creation_accepts_empty_optional_fields_and_applies_role_ownership(string $source, string $role): void
    {
        $actor = $this->{$role};
        $component = $this->creationForm($source, $actor)->set('ownerId', '');
        foreach (['birthDate', 'projectIntention', 'contact'] as $field) {
            $this->assertDoesNotMatchRegularExpression('/<input\b(?=[^>]*wire:model="'.$field.'")[^>]*\brequired(?:\s|=|>)/', $component->html());
        }
        $selfOwned = in_array($role, ['cs', 'manager'], true);
        $hint = $selfOwned ? 'customers.form.owner_self_hint' : 'customers.form.owner_optional_hint';
        $this->assertNotSame($hint, __($hint));
        $component->assertSee(__($hint))
            ->call('save')->assertHasNoErrors();
        $customer = Customer::query()->sole();
        $this->assertNull($customer->birth_date);
        $this->assertNull($customer->project_intention);
        $this->assertSame($selfOwned ? $actor->id : null, $customer->owner_id);
        $this->assertDatabaseCount('customer_contacts', 0);
        $this->assertDatabaseHas('appointments', ['customer_id' => $customer->id, 'owner_id' => $customer->owner_id, 'treatment_project_snapshot' => '']);
        $this->assertDatabaseHas('customer_owner_histories', ['customer_id' => $customer->id, 'to_owner_id' => $customer->owner_id, 'changed_by' => $actor->id]);
        $component->assertRedirect(route($source === 'direct' ? 'direct-customers.show' : 'customers.show', $customer));
        $this->get(route($source === 'direct' ? 'direct-customers.show' : 'customers.show', $customer))->assertOk();
        app(ReminderScheduler::class)->materialize();
        $this->assertSame(2, Reminder::query()->where('customer_id', $customer->id)->where('reminder_type', 'appointment')->count());
        $this->assertSame(0, Reminder::query()->where('customer_id', $customer->id)->whereNotNull('assigned_to')->where('assigned_to', '!=', $customer->owner_id ?? 0)->count());
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function invalidOptionalFields(): iterable
    {
        foreach (['agent', 'direct'] as $source) {
            yield $source.'-date' => [$source, 'birthDate', 'not-a-date'];
            yield $source.'-contact' => [$source, 'contact', str_repeat('x', 256)];
            yield $source.'-intention' => [$source, 'projectIntention', str_repeat('x', 256)];
        }
    }

    #[DataProvider('invalidOptionalFields')]
    public function test_optional_does_not_disable_validation_for_supplied_values(string $source, string $field, string $value): void
    {
        $this->creationForm($source, $this->admin)->set($field, $value)->call('save')->assertHasErrors([$field]);
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('appointments', 0);
    }

    #[DataProvider('sources')]
    public function test_owner_hints_are_translated_on_korean_customer_forms(string $source): void
    {
        $this->admin->update(['preferred_locale' => 'ko_KR']);
        $this->actingAs($this->admin)->get(route($source === 'direct' ? 'direct-customers.create' : 'customers.create'))
            ->assertOk()->assertSee('비워 두면 미배정 상태로 저장됩니다.')
            ->assertDontSee('customers.form.owner_optional_hint');
    }

    #[DataProvider('sources')]
    public function test_optional_owner_still_rejects_a_supplied_user_with_the_wrong_role(string $source): void
    {
        $this->creationForm($source, $this->admin)->set('ownerId', (string) $this->admin->id)
            ->call('save')->assertHasErrors(['ownerId']);
        $this->assertDatabaseCount('customers', 0);
    }

    /** @return array<string, array{string}> */
    public static function sources(): array
    {
        return ['agent' => ['agent'], 'direct' => ['direct']];
    }

    #[DataProvider('sources')]
    public function test_edit_can_clear_and_restore_optional_fields_with_sensitive_confirmation(string $source): void
    {
        $customer = $this->createCustomer($source, $source === 'agent' ? $this->cs : $this->manager, true);
        $form = Livewire::actingAs($this->admin)->test($source === 'direct' ? DirectCustomerForm::class : CustomerForm::class, ['customer' => $customer->id]);
        $form->set('birthDate', '')->set('projectIntention', '')->set('contact', '')->call('save')->assertHasErrors(['sensitiveConfirmation']);
        $this->assertNotNull($customer->refresh()->birth_date);
        $this->assertDatabaseCount('customer_contacts', 1);
        $form->set('sensitiveConfirmation', true)->call('save')->assertHasNoErrors();
        $customer->refresh();
        $this->assertNull($customer->birth_date);
        $this->assertNull($customer->project_intention);
        $this->assertDatabaseCount('customer_contacts', 0);
        $this->assertSame($source === 'agent' ? $this->cs->id : $this->manager->id, $customer->owner_id);
        $this->assertDatabaseHas('appointments', ['customer_id' => $customer->id, 'treatment_project_snapshot' => 'Original intention']);
        $this->assertDatabaseHas('activity_log', ['subject_id' => $customer->id, 'event' => 'updated', 'properties->sensitive_fields_changed' => true]);
        $form->set('birthDate', '1990-05-06')->set('projectIntention', 'New intention')->set('contact', '13800002222')->call('save')->assertHasNoErrors();
        $this->assertSame('1990-05-06', $customer->refresh()->birth_date?->format('Y-m-d'));
        $this->assertSame('New intention', $customer->project_intention);
        $this->assertSame('13800002222', $customer->primaryContact?->value_encrypted);
        $this->assertNotNull(CustomerContact::query()->sole()->lookup_hash);
    }

    #[DataProvider('sources')]
    public function test_staff_creation_cannot_assign_a_forged_owner(string $source): void
    {
        $actor = $source === 'agent' ? $this->cs : $this->manager;
        // The service must enforce ownership even when a caller bypasses the form.
        $customer = $this->createCustomer($source, $this->admin, false, $actor);
        $this->assertSame($actor->id, $customer->owner_id);
        $this->assertDatabaseHas('appointments', ['customer_id' => $customer->id, 'owner_id' => $actor->id]);
    }

    #[DataProvider('sources')]
    public function test_unassigned_customer_can_be_discovered_and_assigned_with_related_work(string $source): void
    {
        $customer = $this->createCustomer($source, null);
        $target = $source === 'agent' ? $this->cs : $this->manager;
        $actor = $source === 'agent' ? $this->bd : $this->admin;
        $show = $source === 'agent' ? 'customers.show' : 'direct-customers.show';
        $list = $source === 'agent' ? 'customers.index' : 'direct-customers.index';
        $this->actingAs($target)->get(route($show, $customer))->assertNotFound();
        $this->actingAs($actor)->get(route($list))->assertOk()->assertSee($customer->code);
        app(ReminderScheduler::class)->materialize();
        $this->assertSame(2, Reminder::query()->where('customer_id', $customer->id)->whereNull('assigned_to')->count());
        app(CustomerTransferManager::class)->direct($customer->id, $target->id, 'Assign unassigned customer', $actor, null);
        $this->assertSame($target->id, $customer->refresh()->owner_id);
        $this->assertDatabaseHas('appointments', ['customer_id' => $customer->id, 'owner_id' => $target->id]);
        $this->assertSame(2, Reminder::query()->where('customer_id', $customer->id)->where('assigned_to', $target->id)->count());
        $this->assertDatabaseHas('customer_owner_histories', ['customer_id' => $customer->id, 'from_owner_id' => null, 'to_owner_id' => $target->id]);
        $this->actingAs($target)->get(route($show, $customer))->assertOk();
    }

    public function test_clearing_and_restoring_birthday_reconciles_only_open_birthday_reminders(): void
    {
        $customer = $this->createCustomer('direct', $this->manager, true);
        $rule = ReminderRule::query()->create([
            'name' => 'Birthday', 'trigger_type' => 'date_offset', 'trigger_config' => ['field' => 'birth_date', 'offset_days' => 0, 'time' => '09:00'],
            'scope_type' => 'all_customers', 'scope_config' => [], 'title' => 'Birthday reminder', 'priority' => 2, 'is_active' => true,
        ]);
        $this->actingAs($this->admin);
        app(ReminderScheduler::class)->materialize();
        $birthday = Reminder::query()->where('rule_id', $rule->id)->sole();
        $birthday->update(['notification_status' => 'sent']);
        $unsent = $birthday->replicate(['dedupe_key']);
        $unsent->fill(['dedupe_key' => hash('sha256', 'unsent-birthday'), 'notification_status' => 'pending'])->save();
        $manual = $birthday->replicate(['dedupe_key']);
        $manual->fill(['dedupe_key' => hash('sha256', 'manual-birthday'), 'source_type' => 'custom'])->save();
        $completed = $birthday->replicate(['dedupe_key']);
        $completed->fill(['dedupe_key' => hash('sha256', 'completed-birthday'), 'status' => 'completed'])->save();
        $form = Livewire::actingAs($this->admin)->test(DirectCustomerForm::class, ['customer' => $customer->id]);
        $form->set('birthDate', '')->call('save')->assertHasNoErrors();
        $this->assertSame('cancelled', $birthday->refresh()->status);
        $this->assertSame('sent', $birthday->notification_status);
        $this->assertSame('cancelled', $unsent->refresh()->notification_status);
        $this->assertSame('pending', $manual->refresh()->status);
        $this->assertSame('completed', $completed->refresh()->status);
        $this->assertSame(2, Reminder::query()->where('customer_id', $customer->id)->where('reminder_type', 'appointment')->where('status', 'pending')->count());
        app(ReminderScheduler::class)->materialize();
        $this->assertSame('cancelled', $birthday->refresh()->status);
        $form->set('birthDate', '1990-10-20')->call('save')->assertHasNoErrors();
        app(ReminderScheduler::class)->materialize();
        $this->assertSame('pending', $birthday->refresh()->status);
        $this->assertSame('sent', $birthday->notification_status);
        $this->assertSame('completed', $completed->refresh()->status);
        $birthday->update(['status' => 'cancelled']);
        ReminderEvent::query()->create([
            'reminder_id' => $birthday->id, 'event' => 'cancelled', 'actor_id' => $this->admin->id,
            'properties' => ['source' => 'manual'], 'occurred_at' => now(),
        ]);
        app(ReminderScheduler::class)->materialize();
        $this->assertSame('cancelled', $birthday->refresh()->status);
    }

    /** @return array<string, array{string}> */
    public static function registrationEntries(): array
    {
        return ['manual' => ['manual'], 'excel' => ['excel'], 'center' => ['center']];
    }

    #[DataProvider('registrationEntries')]
    public function test_all_registration_entries_reject_unassigned_direct_customer(string $entry): void
    {
        $customer = $this->createCustomer('direct', null);
        $customer->update(['current_status_id' => CustomerStatus::query()->where('key', 'arrived')->value('id'), 'arrived_at' => now()]);
        $form = $entry === 'center'
            ? Livewire::actingAs($this->admin)->test(InstitutionReturnCenter::class)->set('customerId', (string) $customer->id)
            : Livewire::actingAs($this->admin)->test(CustomerOrderRegistration::class, ['customerId' => $customer->id]);
        $form->set('institutionId', (string) $this->institution->id)->set('communicationScreenshots', [UploadedFile::fake()->image('evidence.png')]);
        if ($entry === 'manual') {
            $form->set('items', [['project_name' => 'Treatment', 'amount_krw' => '100000']]);
        } else {
            $generated = app(InstitutionFormTemplateService::class)->generate($this->institution->id, $customer->id);
            $sheet = IOFactory::load($generated['path']);
            $sheet->getSheetByName('机构回传')->fromArray([$customer->name, '2026-10-15', 'Treatment', 1, 100000, ''], null, 'A2');
            (new Xlsx($sheet))->save($generated['path']);
            $form->set('upload', UploadedFile::fake()->createWithContent('return.xlsx', file_get_contents($generated['path'])));
            unlink($generated['path']);
        }
        $form->call($entry === 'manual' ? 'registerOrder' : 'uploadReturn')->assertHasErrors(['upload'])
            ->assertSee(__('orders.errors.direct_customer_owner_required'));
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('direct_order_commissions', 0);
        $this->assertSame('arrived', $customer->refresh()->currentStatus?->key);
    }

    public function test_daily_order_gateway_cannot_complete_an_unassigned_direct_customer(): void
    {
        $customer = $this->createCustomer('direct', null);
        $this->actingAs($this->admin);
        $gateway = app(DatabaseDailyOrderGateway::class);
        try {
            $gateway->create(new DailyOrderData($customer->id, $this->institution->id, null, 'Treatment', 100000, 'completed', CarbonImmutable::now(), null, null, $this->admin->id, null));
            $this->fail('An unassigned direct customer must not receive a completed order.');
        } catch (DomainException $exception) {
            $this->assertSame(__('orders.errors.direct_customer_owner_required'), $exception->getMessage());
        }
        $this->assertDatabaseCount('orders', 0);
        $order = Order::query()->create(['customer_id' => $customer->id, 'institution_id' => $this->institution->id, 'source_type' => 'direct', 'project_name' => 'Treatment', 'amount_krw' => 100000, 'status' => 'pending']);
        try {
            $gateway->complete($order->id, CarbonImmutable::now(), $this->admin->id, null);
            $this->fail('An unassigned direct customer must not complete a pending order.');
        } catch (DomainException $exception) {
            $this->assertSame(__('orders.errors.direct_customer_owner_required'), $exception->getMessage());
        }
        $this->assertSame('pending', $order->refresh()->status);
        $this->assertDatabaseCount('direct_order_commissions', 0);
    }

    public function test_assignment_allows_the_new_direct_owner_to_complete_a_preexisting_pending_order(): void
    {
        $customer = $this->createCustomer('direct', null);
        $gateway = app(DatabaseDailyOrderGateway::class);
        $orderId = $gateway->create(new DailyOrderData($customer->id, $this->institution->id, null, 'Treatment', 100000, 'pending', null, null, null, $this->admin->id, null));
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'owner_id' => null]);
        app(CustomerTransferManager::class)->direct($customer->id, $this->manager->id, 'Assign customer', $this->admin, null);
        DirectCommissionRate::query()->create(['rate_bps' => 300, 'effective_from' => '2026-01-01', 'reason' => 'Test rate', 'created_by' => $this->admin->id]);
        $this->actingAs($this->manager);
        $gateway->complete($orderId, CarbonImmutable::now(), $this->manager->id, null);
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'status' => 'completed', 'owner_id' => $this->manager->id]);
        $this->assertDatabaseHas('direct_order_commissions', ['order_id' => $orderId, 'owner_id' => $this->manager->id, 'commission_amount_krw' => 3000]);
    }

    private function creationForm(string $source, User $actor): Testable
    {
        $form = Livewire::actingAs($actor)->test($source === 'direct' ? DirectCustomerForm::class : CustomerForm::class)
            ->set('name', 'Optional customer')->set('identityDocument', 'P-OPTIONAL')
            ->set('institutionId', (string) $this->institution->id)->set('arrivalAt', '2026-10-20T10:00');
        $form->set($source === 'direct' ? 'channelId' : 'sourceAgentId', (string) ($source === 'direct' ? DirectCustomerChannel::query()->firstOrFail()->id : $this->agent->id));

        return $form->call('refreshCode')->set('codeConfirmed', true);
    }

    private function createCustomer(string $source, ?User $owner, bool $filled = false, ?User $actor = null): Customer
    {
        $actor ??= $this->admin;
        $this->actingAs($actor);
        $service = app(CustomerProfileManager::class);
        $id = $service->create(
            profile: new CustomerProfileData('Optional customer', null, $filled ? CarbonImmutable::parse('1990-10-20') : null,
                $source === 'agent' ? $this->agent->id : null, $filled ? '13800001111' : '', 'P-OPTIONAL',
                $filled ? 'Original intention' : '', null, $source, $source === 'direct' ? DirectCustomerChannel::query()->firstOrFail()->id : null),
            institutionId: $this->institution->id, arrivalAt: CarbonImmutable::parse('2026-10-20 10:00:00'), translatorName: null,
            actorId: $actor->id, ownerId: $owner?->id,
            confirmedCode: $source === 'direct' ? $service->previewDirectCode() : $service->previewCode($this->agent->id), automaticCode: true, ipAddress: null,
        );

        return Customer::query()->findOrFail($id);
    }
}
