<?php

namespace Tests\Feature;

use App\Modules\Customer\Application\Contracts\CustomerImportGateway;
use App\Modules\Customer\Application\Data\CustomerImportData;
use App\Modules\Customer\Infrastructure\Models\CustomerContact;
use Carbon\CarbonImmutable;
use Database\Seeders\PhaseTwoReferenceDataSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PhaseTwoDataModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_reference_data_and_channel_constraints_are_installed(): void
    {
        $this->seed(PhaseTwoReferenceDataSeeder::class);

        $this->assertDatabaseHas('agent_type_codes', ['code' => 'KR', 'is_system' => true]);
        $this->assertDatabaseHas('institutions', ['code' => 'BLANCHE']);
        $this->assertDatabaseHas('customer_statuses', ['name' => '沉默待唤醒']);

        $this->expectException(QueryException::class);
        DB::table('customers')->insert([
            'code' => 'INVALID-000001',
            'name' => '约束测试',
            'original_channel' => 'direct',
            'source_agent_id' => null,
            'source_direct_sales_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_sensitive_contact_is_encrypted_and_can_be_matched_by_blind_index(): void
    {
        $this->seed(PhaseTwoReferenceDataSeeder::class);
        DB::table('direct_sales_sources')->insert([
            'code' => 'ZX',
            'name' => '自然直销',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $sourceId = (int) DB::table('direct_sales_sources')->value('id');

        $gateway = app(CustomerImportGateway::class);
        $customerId = $gateway->upsertCustomer(new CustomerImportData(
            code: 'ZX-000001',
            legacyCode: null,
            name: '测试客户',
            gender: null,
            birthDate: CarbonImmutable::parse('2000-01-01'),
            originalChannel: 'direct',
            sourceAgentId: null,
            sourceDirectSalesId: $sourceId,
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
}
