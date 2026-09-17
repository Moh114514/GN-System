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
use App\Modules\Config\Infrastructure\Models\Institution;
use App\Modules\Customer\Application\Services\CustomerDirectory;
use App\Modules\Customer\Infrastructure\Models\Customer;
use App\Modules\Customer\Infrastructure\Models\CustomerStatus;
use App\Modules\Order\Infrastructure\Models\Order;
use App\Modules\Reminder\Application\Services\ReminderWorkspace;
use App\Modules\Report\Application\Services\DashboardRangeFactory;
use App\Modules\Report\Application\Services\DashboardService;
use App\Modules\Report\Application\Services\ReportExportManager;
use App\Modules\Report\Application\Services\TeamOverviewService;
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

    private BusinessGroup $groupA;

    private BusinessGroup $groupB;

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

        $this->groupA = BusinessGroup::query()->create(['code' => 'SCOPE-A', 'name' => '范围 A', 'is_active' => true, 'created_by' => $this->admin->id]);
        $this->groupB = BusinessGroup::query()->create(['code' => 'SCOPE-B', 'name' => '范围 B', 'is_active' => true, 'created_by' => $this->admin->id]);
        foreach ([[$this->bd, 'bd_manager'], [$this->owner, 'customer_service'], [$this->peer, 'customer_service']] as [$user, $role]) {
            BusinessGroupMembership::query()->create([
                'business_group_id' => $this->groupA->id,
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
        foreach ([[$agentA, $this->groupA], [$agentB, $this->groupB]] as [$agent, $group]) {
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

    public function test_team_overview_is_scoped_for_bd_and_drills_down_for_super_admin(): void
    {
        $bdContent = $this->actingAs($this->bd)->get(route('team-overview.index'))
            ->assertOk()
            ->assertSee('团队管理')
            ->assertSee('范围 A')
            ->assertSee('组内负责人客服')
            ->assertDontSee('范围 B')
            ->assertSee(route('customers.index', ['ownerId' => $this->owner->id]), false)
            ->getContent();

        $this->assertStringNotContainsString('客户 B', $bdContent);

        $this->actingAs($this->owner)->get(route('team-overview.index'))->assertForbidden();

        $adminContent = $this->actingAs($this->admin)->get(route('team-overview.index'))
            ->assertOk()
            ->assertSee('范围 A')
            ->assertSee('范围 B')
            ->getContent();

        $this->assertStringContainsString(
            route('team-overview.index', ['groupId' => $this->groupA->id]),
            $adminContent,
        );

        $this->actingAs($this->admin)->get(route('team-overview.index', ['groupId' => $this->groupA->id]))
            ->assertOk()
            ->assertSee(__('team.group_detail'))
            ->assertSee(__('team.workload'))
            ->assertSee('组内负责人客服');

        $this->actingAs($this->bd)->get(route('team-overview.index', ['groupId' => $this->groupB->id]))
            ->assertNotFound();
    }

    public function test_team_overview_does_not_reject_a_group_without_agent_assignments(): void
    {
        $emptyGroup = BusinessGroup::query()->create([
            'code' => 'SCOPE-EMPTY',
            'name' => '范围空组',
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)->get(route('team-overview.index'))
            ->assertOk()
            ->assertSee($emptyGroup->code)
            ->assertSee($emptyGroup->name);
    }

    public function test_team_overview_uses_historical_order_facts_and_keeps_unset_status(): void
    {
        $institutionId = Institution::query()->value('id');
        $movedAgent = Agent::query()->create([
            'agent_type_code_id' => AgentTypeCode::query()->value('id'),
            'code' => 'SCOPE-MOVED',
            'name' => '迁移代理商',
            'cooperation_status' => 'active',
        ]);
        AgentBusinessGroupAssignment::query()->create([
            'agent_id' => $movedAgent->id,
            'business_group_id' => $this->groupA->id,
            'effective_from' => '2026-01-01',
            'effective_until' => '2026-08-15',
            'assigned_by' => $this->admin->id,
            'reason' => '历史订单口径测试',
        ]);

        $this->groupACustomer->update(['current_status_id' => null]);
        $snapshot = [
            'business_group' => ['business_group_id' => $this->groupA->id],
            'occurred_on' => '2026-08-10',
        ];
        foreach ([
            ['amount_krw' => 1_000_000, 'owner_id' => $this->peer->id, 'occurred_on' => '2026-08-10', 'record_status' => 'active'],
            ['amount_krw' => 2_000_000, 'owner_id' => null, 'occurred_on' => '2026-08-11', 'record_status' => 'active'],
            ['amount_krw' => 9_000_000, 'owner_id' => $this->owner->id, 'occurred_on' => '2026-07-31', 'record_status' => 'active'],
            ['amount_krw' => 7_000_000, 'owner_id' => $this->owner->id, 'occurred_on' => '2026-08-12', 'record_status' => 'voided'],
        ] as $order) {
            Order::query()->create([
                'customer_id' => $this->groupACustomer->id,
                'institution_id' => $institutionId,
                'agent_id' => $movedAgent->id,
                'project_name' => '历史口径测试项目',
                'amount_krw' => $order['amount_krw'],
                'completed_on' => $order['occurred_on'],
                'occurred_on' => $order['occurred_on'],
                'completed_at' => '2026-08-29 09:00:00',
                'completion_precision' => 'date',
                'status' => 'completed',
                'record_status' => $order['record_status'],
                'owner_id' => $order['owner_id'],
                'business_attribution_snapshot' => $snapshot,
            ]);
        }
        AgentBusinessGroupAssignment::query()->create([
            'agent_id' => $movedAgent->id,
            'business_group_id' => $this->groupB->id,
            'effective_from' => '2026-08-16',
            'effective_until' => null,
            'assigned_by' => $this->admin->id,
            'reason' => '历史订单口径测试',
        ]);
        BusinessGroupMembership::query()
            ->where('business_group_id', $this->groupA->id)
            ->where('user_id', $this->peer->id)
            ->update(['effective_until' => '2026-08-15']);

        $this->actingAs($this->admin);
        $team = app(TeamOverviewService::class)->snapshot($this->groupA->id);
        $selected = $team['selected_group'];

        $this->assertSame(3_000_000, $selected['amount_krw']);
        $this->assertSame(2, $selected['orders']);
        $this->assertSame(3_000_000, $team['overview']['amount_krw']);
        $this->assertSame(1, $selected['customer_service_count']);
        $this->assertSame(1, $selected['owners'][0]['unset']);
    }

    public function test_team_overview_includes_paused_agents_and_marks_invalid_owners(): void
    {
        $pausedAgent = Agent::query()->create([
            'agent_type_code_id' => AgentTypeCode::query()->value('id'),
            'code' => 'SCOPE-PAUSED',
            'name' => '暂停代理商',
            'cooperation_status' => 'paused',
        ]);
        AgentBusinessGroupAssignment::query()->create([
            'agent_id' => $pausedAgent->id,
            'business_group_id' => $this->groupA->id,
            'effective_from' => '2026-01-01',
            'effective_until' => null,
            'assigned_by' => $this->admin->id,
            'reason' => '暂停代理商范围测试',
        ]);
        Customer::query()->create([
            'code' => 'SCOPE-PAUSED-CUSTOMER',
            'name' => '暂停代理商客户',
            'source_agent_id' => $pausedAgent->id,
            'current_status_id' => $this->groupACustomer->current_status_id,
            'owner_id' => $this->peer->id,
        ]);

        $this->actingAs($this->admin);
        $before = app(TeamOverviewService::class)->snapshot($this->groupA->id)['selected_group'];
        $this->assertSame(2, $before['customer_count']);
        $this->assertSame(1, $before['agent_count']);

        $this->owner->update(['is_active' => false]);
        BusinessGroupMembership::query()
            ->where('business_group_id', $this->groupA->id)
            ->where('user_id', $this->owner->id)
            ->update(['effective_until' => '2026-08-15']);
        $after = app(TeamOverviewService::class)->snapshot($this->groupA->id)['selected_group'];

        $this->assertSame(2, $after['customer_count']);
        $this->assertSame(1, $after['owner_exception_customers']);
        $this->assertSame(0, $after['unassigned_customers']);
        $this->assertTrue($after['has_attention']);
        $this->assertSame(1, app(CustomerDirectory::class)->paginate([
            'business_group_id' => $this->groupA->id,
            'owner_state' => 'invalid',
        ], 20)->total());
    }

    public function test_historical_order_attribution_backfill_is_previewable_audited_and_explicit(): void
    {
        $order = Order::query()->create([
            'customer_id' => $this->groupACustomer->id,
            'institution_id' => Institution::query()->value('id'),
            'agent_id' => $this->groupACustomer->source_agent_id,
            'project_name' => '历史快照回填测试',
            'amount_krw' => 100000,
            'completed_on' => '2026-08-10',
            'occurred_on' => '2026-08-10',
            'completed_at' => '2026-08-10 10:00:00',
            'completion_precision' => 'date',
            'status' => 'completed',
            'record_status' => 'active',
            'owner_id' => $this->owner->id,
            'business_attribution_snapshot' => null,
        ]);

        $this->artisan('app:backfill-order-attribution-snapshots')
            ->assertExitCode(0)
            ->expectsOutputToContain('Preview only');
        $this->assertNull($order->refresh()->business_attribution_snapshot);

        $this->artisan('app:backfill-order-attribution-snapshots', [
            '--apply' => true,
            '--actor' => $this->admin->id,
            '--reason' => '补齐历史订单归属事实',
        ])->assertExitCode(0);
        $snapshot = $order->refresh()->business_attribution_snapshot;

        $this->assertIsArray($snapshot);
        $this->assertSame($this->groupA->id, $snapshot['business_group']['business_group_id']);
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => $order->getMorphClass(),
            'subject_id' => $order->id,
            'event' => 'attribution_snapshot_backfilled',
        ]);
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
