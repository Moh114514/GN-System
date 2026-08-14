<?php

namespace Tests\Feature;

use App\Modules\Customer\Application\Contracts\CustomerImportGateway;
use App\Modules\Customer\Application\Data\CustomerImportData;
use App\Modules\Customer\Infrastructure\Models\CustomerContact;
use Carbon\CarbonImmutable;
use Database\Seeders\PhaseTwoReferenceDataSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PhaseTwoDataModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_reference_data_and_agent_constraints_are_installed(): void
    {
        $this->seed(PhaseTwoReferenceDataSeeder::class);

        $this->assertDatabaseHas('agent_type_codes', ['code' => 'KR', 'is_system' => true]);
        $this->assertDatabaseHas('institutions', ['code' => 'BLANCHE']);
        $this->assertDatabaseHas('customer_statuses', ['name' => '沉默待唤醒']);
        $this->assertDatabaseCount('customer_status_transitions', 6);

        $this->expectException(QueryException::class);
        DB::table('customers')->insert([
            'code' => 'INVALID-000001',
            'name' => '约束测试',
            'source_agent_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_sensitive_contact_is_encrypted_and_can_be_matched_by_blind_index(): void
    {
        $this->seed(PhaseTwoReferenceDataSeeder::class);
        $agentId = (int) DB::table('agents')->insertGetId([
            'agent_type_code_id' => DB::table('agent_type_codes')->where('code', 'JG')->value('id'),
            'code' => 'TEST-JG',
            'name' => '测试代理商',
            'cooperation_status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $gateway = app(CustomerImportGateway::class);
        $customerId = $gateway->upsertCustomer(new CustomerImportData(
            code: 'ZX-000001',
            legacyCode: null,
            name: '测试客户',
            gender: null,
            birthDate: CarbonImmutable::parse('2000-01-01'),
            sourceAgentId: $agentId,
            statusName: '意向',
            wechatAddedOn: null,
            contactValue: '010-1234-5678',
            identityDocument: 'P1234567',
            projectIntention: null,
            notes: null,
            importBatchId: '00000000-0000-0000-0000-000000000001',
        ));

        $raw = DB::table('customer_contacts')->where('customer_id', $customerId)->value('value_encrypted');
        $this->assertIsString($raw);
        $this->assertStringNotContainsString('010-1234-5678', $raw);
        $this->assertSame('010-1234-5678', CustomerContact::query()->firstOrFail()->value_encrypted);
        $this->assertSame([$customerId], $gateway->duplicateCandidateIds('01012345678', 'P1234567'));
    }

    public function test_direct_sales_removal_migration_refuses_legacy_direct_customer_rows(): void
    {
        $this->seed(PhaseTwoReferenceDataSeeder::class);
        $this->addLegacySalesColumns();
        $agentId = $this->createTestAgent();

        DB::table('customers')->insert([
            'code' => 'DIRECT-000001',
            'name' => '历史直销客户',
            'source_agent_id' => $agentId,
            'original_channel' => 'direct',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_08_14_000100_remove_direct_sales_business.php');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('found 1 direct customer row');
        $migration->up();
    }

    public function test_direct_sales_removal_migration_refuses_legacy_direct_order_rows(): void
    {
        $this->seed(PhaseTwoReferenceDataSeeder::class);
        $this->addLegacySalesColumns();
        $agentId = $this->createTestAgent();
        $customerId = (int) DB::table('customers')->insertGetId([
            'code' => 'AGENT-000001',
            'name' => '代理商客户',
            'source_agent_id' => $agentId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('orders')->insert([
            'customer_id' => $customerId,
            'institution_id' => DB::table('institutions')->value('id'),
            'agent_id' => $agentId,
            'project_name' => '历史直销订单',
            'amount_krw' => 100,
            'channel' => 'direct',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_08_14_000100_remove_direct_sales_business.php');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('found 0 direct customer row(s) and 1 direct order row(s)');
        $migration->up();
    }

    public function test_direct_sales_removal_migration_refuses_rows_without_agent_ownership(): void
    {
        $this->seed(PhaseTwoReferenceDataSeeder::class);
        $this->addLegacySalesColumns();
        $agentId = $this->createTestAgent();

        DB::statement('ALTER TABLE customers ALTER COLUMN source_agent_id DROP NOT NULL');
        $missingAgentCustomerId = (int) DB::table('customers')->insertGetId([
            'code' => 'MISSING-000001',
            'name' => '缺少代理商客户',
            'source_agent_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::statement('ALTER TABLE orders ALTER COLUMN agent_id DROP NOT NULL');
        DB::table('orders')->insert([
            'customer_id' => $missingAgentCustomerId,
            'institution_id' => DB::table('institutions')->value('id'),
            'agent_id' => null,
            'project_name' => '缺少代理商订单',
            'amount_krw' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_08_14_000100_remove_direct_sales_business.php');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('found 1 customer row(s) and 1 order row(s) without an agent');
        $migration->up();
    }

    public function test_direct_sales_removal_migration_drops_legacy_schema_without_business_rows(): void
    {
        $this->addLegacySalesColumns();
        $migration = require database_path('migrations/2026_08_14_000100_remove_direct_sales_business.php');

        $migration->up();

        $this->assertFalse(Schema::hasColumn('customers', 'original_channel'));
        $this->assertFalse(Schema::hasColumn('customers', 'source_direct_sales_id'));
        $this->assertFalse(Schema::hasColumn('orders', 'channel'));
        $this->assertFalse(Schema::hasColumn('orders', 'direct_sales_source_id'));
        $this->assertFalse(Schema::hasTable('direct_sales_sources'));
    }

    public function test_direct_sales_removal_migration_cannot_be_rolled_back(): void
    {
        $migration = require database_path('migrations/2026_08_14_000100_remove_direct_sales_business.php');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('irreversible');
        $migration->down();
    }

    private function addLegacySalesColumns(): void
    {
        Schema::create('direct_sales_sources', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 6);
            $table->string('name');
        });
        Schema::table('customers', function (Blueprint $table): void {
            $table->string('original_channel', 16)->nullable();
            $table->unsignedBigInteger('source_direct_sales_id')->nullable();
        });
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('channel', 16)->nullable();
            $table->unsignedBigInteger('direct_sales_source_id')->nullable();
        });
    }

    private function createTestAgent(): int
    {
        return (int) DB::table('agents')->insertGetId([
            'agent_type_code_id' => DB::table('agent_type_codes')->where('code', 'KR')->value('id'),
            'code' => 'PR1-TEST',
            'name' => 'PR1 测试代理商',
            'cooperation_status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
