<?php

namespace Tests\Feature;

use App\Infrastructure\Time\BusinessClock;
use App\Models\User;
use App\Modules\Agent\Application\Contracts\AgentReferenceReader;
use App\Modules\Agent\Infrastructure\Models\Agent;
use App\Modules\Agent\Infrastructure\Models\AgentBusinessGroupAssignment;
use App\Modules\Agent\Infrastructure\Models\AgentTypeCode;
use App\Modules\Auth\Application\Contracts\AccessContextResolver;
use App\Modules\Auth\Domain\UserRole;
use App\Modules\Auth\Infrastructure\Models\BusinessGroup;
use App\Modules\Auth\Infrastructure\Models\BusinessGroupMembership;
use App\Modules\Customer\Application\Services\CustomerDirectory;
use App\Modules\Customer\Infrastructure\Models\Customer;
use App\Modules\Customer\Infrastructure\Models\CustomerStatus;
use App\Modules\Reminder\Application\Services\ReminderWorkspace;
use App\Modules\Report\Application\Services\DashboardRangeFactory;
use App\Modules\Report\Application\Services\DashboardService;
use App\Modules\Report\Application\Services\ReportExportManager;
use Carbon\CarbonImmutable;
use Database\Seeders\PhaseTwoReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AccessScopeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $bd;

    private User $owner;

    private User $peer;

    private Customer $groupACustomer;

    private Customer $groupBCustomer;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-08-24 10:00:00');
        $this->seed(PhaseTwoReferenceDataSeeder::class);
        $this->admin = User::factory()->superAdmin()->withTwoFactor()->create();
        $this->bd = User::factory()->create(['role' => UserRole::BdManager]);
        $this->owner = User::factory()->create(['name' => '组内负责人客服']);
        $this->peer = User::factory()->create(['name' => '组内非负责人客服']);

        $groupA = BusinessGroup::query()->create(['code' => 'SCOPE-A', 'name' => '范围 A', 'is_active' => true, 'created_by' => $this->admin->id]);
        $groupB = BusinessGroup::query()->create(['code' => 'SCOPE-B', 'name' => '范围 B', 'is_active' => true, 'created_by' => $this->admin->id]);
        foreach ([[$this->bd, 'bd_manager'], [$this->owner, 'customer_service'], [$this->peer, 'customer_service']] as [$user, $role]) {
            BusinessGroupMembership::query()->create([
                'business_group_id' => $groupA->id,
                'user_id' => $user->id,
                'member_role' => $role,
                'effective_from' => '2026-01-01',
                'effective_until' => null,
                'assigned_by' => $this->admin->id,
                'reason' => 'PR2 scope test',
            ]);
        }

        $typeId = AgentTypeCode::query()->value('id');
        $agentA = Agent::query()->create(['agent_type_code_id' => $typeId, 'code' => 'SCOPE-A', 'name' => '代理商 A', 'cooperation_status' => 'active']);
        $agentB = Agent::query()->create(['agent_type_code_id' => $typeId, 'code' => 'SCOPE-B', 'name' => '代理商 B', 'cooperation_status' => 'active']);
        foreach ([[$agentA, $groupA], [$agentB, $groupB]] as [$agent, $group]) {
            AgentBusinessGroupAssignment::query()->create([
                'agent_id' => $agent->id,
                'business_group_id' => $group->id,
                'effective_from' => '2026-01-01',
                'effective_until' => null,
                'assigned_by' => $this->admin->id,
                'reason' => 'PR2 scope test',
            ]);
        }

        $statusId = CustomerStatus::query()->where('key', 'booked')->value('id');
        $this->groupACustomer = Customer::query()->create([
            'code' => 'SCOPE-CUSTOMER-A',
            'name' => '客户 A',
            'source_agent_id' => $agentA->id,
            'current_status_id' => $statusId,
            'owner_id' => $this->owner->id,
        ]);
        $this->groupBCustomer = Customer::query()->create([
            'code' => 'SCOPE-CUSTOMER-B',
            'name' => '客户 B',
            'source_agent_id' => $agentB->id,
            'current_status_id' => $statusId,
            'owner_id' => User::factory()->create()->id,
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_four_identity_matrix_and_cross_group_urls_are_scoped(): void
    {
        $this->actingAs($this->admin)->get(route('customers.index'))
            ->assertOk()
            ->assertSee('客户 A')
            ->assertSee('客户 B');

        foreach ([$this->bd, $this->owner, $this->peer] as $user) {
            $this->actingAs($user)->get(route('customers.index'))
                ->assertOk()
                ->assertSee('客户 A')
                ->assertDontSee('客户 B');
            $this->get(route('customers.show', $this->groupBCustomer->id))->assertNotFound();
        }
    }

    public function test_customer_service_non_owner_gets_no_sensitive_customer_values_and_cannot_export(): void
    {
        $this->actingAs($this->peer);
        $profile = app(CustomerDirectory::class)->profile($this->groupACustomer->id);

        $this->assertNull($profile['contact']);
        $this->assertNull($profile['identity_document']);

        $this->expectException(HttpException::class);
        app(ReportExportManager::class)->queueSearch($this->peer, []);
    }

    public function test_customer_list_supports_scoped_owner_filter_and_customer_service_own_first_order(): void
    {
        $peerCustomer = Customer::query()->create([
            'code' => 'SCOPE-CUSTOMER-A-PEER',
            'name' => '客户 A（组内其他负责人）',
            'source_agent_id' => $this->groupACustomer->source_agent_id,
            'current_status_id' => $this->groupACustomer->current_status_id,
            'owner_id' => $this->peer->id,
            'created_at' => '2026-08-24 11:00:00',
            'updated_at' => '2026-08-24 11:00:00',
        ]);
        $this->groupACustomer->update(['created_at' => '2026-08-24 09:00:00', 'updated_at' => '2026-08-24 09:00:00']);

        $this->actingAs($this->owner);
        $directory = app(CustomerDirectory::class);

        $page = $directory->paginate([], 20);
        $this->assertSame([$this->groupACustomer->id, $peerCustomer->id], $page->getCollection()->pluck('id')->all());
        $this->assertSame([$peerCustomer->id], $directory->paginate(['owner_id' => $this->peer->id], 20)->getCollection()->pluck('id')->all());
        $this->assertEqualsCanonicalizing(
            [$this->owner->id, $this->peer->id],
            array_column($directory->ownerCandidates(), 'id'),
        );

        $this->assertSame([], $directory->paginate(['owner_id' => $this->groupBCustomer->owner_id], 20)->getCollection()->pluck('id')->all());

        $this->actingAs($this->bd)->get(route('customers.index', ['ownerId' => $this->peer->id]))
            ->assertOk()
            ->assertSee('SCOPE-CUSTOMER-A-PEER')
            ->assertDontSee('SCOPE-CUSTOMER-A</div>', false);
    }

    public function test_access_fingerprint_and_dashboard_cache_differ_by_identity(): void
    {
        $resolver = app(AccessContextResolver::class);
        $adminContext = $resolver->forUser($this->admin);
        $bdContext = $resolver->forUser($this->bd);
        $this->assertNotSame($adminContext->fingerprint, $bdContext->fingerprint);

        $range = app(DashboardRangeFactory::class)->make('month');
        $this->actingAs($this->admin);
        $adminSnapshot = app(DashboardService::class)->snapshot($range, true);
        $this->actingAs($this->bd);
        $bdSnapshot = app(DashboardService::class)->snapshot($range, true);

        $this->assertNotSame(
            $adminSnapshot->metrics['new_customers']['value'],
            $bdSnapshot->metrics['new_customers']['value'],
        );
    }

    public function test_customer_service_without_group_or_agent_scope_is_denied_everywhere(): void
    {
        $unassigned = User::factory()->create(['role' => UserRole::CustomerService]);
        $context = app(AccessContextResolver::class)->forUser($unassigned);

        $this->assertSame([], $context->businessGroupIds);
        $this->assertSame([], $context->agentIds);

        $this->actingAs($unassigned);
        $this->assertSame([], app(AgentReferenceReader::class)->activeAgents());
        $this->assertSame([], app(CustomerDirectory::class)->ownerCandidates());
        $this->assertSame(0, app(CustomerDirectory::class)->paginate([], 15)->total());
        $this->assertSame([], app(ReminderWorkspace::class)->assigneeCandidates());
    }

    public function test_permission_effective_dates_follow_business_clock(): void
    {
        $membership = BusinessGroupMembership::query()->where('user_id', $this->owner->id)->firstOrFail();
        $assignment = AgentBusinessGroupAssignment::query()->where('agent_id', $this->groupACustomer->source_agent_id)->firstOrFail();
        $membership->update(['effective_until' => '2026-08-31']);
        $assignment->update(['effective_until' => '2026-08-31']);

        $clock = app(BusinessClock::class);
        $clock->set(CarbonImmutable::parse('2026-09-01'), $this->admin->id);
        try {
            $context = app(AccessContextResolver::class)->forUser($this->owner);
            $this->assertSame([], $context->businessGroupIds);
            $this->assertSame([], $context->agentIds);
        } finally {
            $clock->disable();
        }
    }
}
