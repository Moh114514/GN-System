<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Auth\Application\Contracts\AccessContextResolver;
use App\Modules\Auth\Application\Contracts\BusinessGroupManagementGateway;
use App\Modules\Auth\Application\Data\AccessContext;
use App\Modules\Auth\Domain\UserRole;
use App\Modules\Customer\Domain\CustomerSourceType;
use Database\Seeders\PhaseTwoReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DirectCustomerBusinessFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_direct_customer_schema_is_seeded_and_role_is_available(): void
    {
        $this->assertTrue(Schema::hasTable('direct_customer_channels'));
        $this->assertTrue(Schema::hasColumn('customers', 'source_type'));
        $this->assertTrue(Schema::hasColumn('customers', 'direct_channel_id'));
        $this->assertTrue(Schema::hasColumn('orders', 'source_type'));

        $this->assertSame(
            ['xiaohongshu', 'douyin', 'wechat', 'website', 'phone', 'offline', 'referral', 'other'],
            DB::table('direct_customer_channels')->orderBy('sort_order')->pluck('code')->all(),
        );
        $this->assertSame(UserRole::DirectCustomerManager, UserRole::tryFrom('direct_customer_manager'));
        $this->assertSame(CustomerSourceType::Direct, CustomerSourceType::tryFrom('direct'));
    }

    public function test_reference_seeder_restores_default_direct_channels_after_business_reset(): void
    {
        DB::table('direct_customer_channels')->truncate();

        $this->seed(PhaseTwoReferenceDataSeeder::class);

        $this->assertSame(
            ['xiaohongshu', 'douyin', 'wechat', 'website', 'phone', 'offline', 'referral', 'other'],
            DB::table('direct_customer_channels')->orderBy('sort_order')->pluck('code')->all(),
        );
    }

    public function test_direct_customer_manager_is_not_a_business_group_member(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $manager = User::factory()->create(['role' => UserRole::DirectCustomerManager]);
        $group = app(BusinessGroupManagementGateway::class)->create('DIRECT', '直客组', $admin->id, null);

        $this->expectException(\DomainException::class);
        app(BusinessGroupManagementGateway::class)->assignMember(
            $group['id'],
            $manager->id,
            '2026-09-01',
            null,
            '不应加入代理业务组',
            $admin->id,
            null,
        );
    }

    public function test_direct_customer_manager_context_is_owner_scoped_and_separate_from_agent_scope(): void
    {
        $manager = User::factory()->create(['role' => UserRole::DirectCustomerManager]);
        $other = User::factory()->create(['role' => UserRole::DirectCustomerManager]);
        $context = app(AccessContextResolver::class)->forUser($manager);

        $this->assertTrue($context->isDirectCustomerManager());
        $this->assertSame([], $context->businessGroupIds);
        $this->assertSame([], $context->agentIds);
        $this->assertTrue($context->canViewCustomer('direct', null, $manager->id));
        $this->assertFalse($context->canViewCustomer('direct', null, $other->id));
        $this->assertFalse($context->canViewCustomer('agent', 1, $manager->id));
        $this->assertTrue($context->canViewOrder('direct', null, $manager->id));
        $this->assertFalse($context->canViewOrder('agent', 1, $manager->id));
    }

    public function test_customer_service_and_bd_contexts_use_their_respective_source_rules(): void
    {
        $customerService = User::factory()->create(['role' => UserRole::CustomerService]);
        $customerContext = new AccessContext($customerService->id, UserRole::CustomerService->value, [], [], [], false);
        $this->assertTrue($customerContext->canViewCustomer('agent', 1, $customerService->id));
        $this->assertTrue($customerContext->canViewCustomer('direct', null, $customerService->id));
        $this->assertFalse($customerContext->canViewCustomer('agent', 1, $customerService->id + 1));

        $bdContext = new AccessContext(10, UserRole::BdManager->value, [1], [7], [10], false);
        $this->assertTrue($bdContext->canViewCustomer('agent', 7, 99));
        $this->assertFalse($bdContext->canViewCustomer('direct', null, 10));
        $this->assertFalse($bdContext->canViewOrder('direct', null, 10));
        $this->assertTrue($bdContext->canViewOrder('agent', 7, 99));
    }
}
