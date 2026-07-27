<?php

namespace Tests\Feature;

use Database\Seeders\PhaseTwoDemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhaseTwoDemoDataSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_data_is_complete_and_idempotent(): void
    {
        $this->seed(PhaseTwoDemoDataSeeder::class);
        $this->assertDemoCounts();

        $this->seed(PhaseTwoDemoDataSeeder::class);
        $this->assertDemoCounts();

        $this->assertDatabaseHas('customers', [
            'code' => 'DM01-JG-0001',
            'name' => '【模拟】渠道客户01-01',
        ]);
        $this->assertDatabaseHas('orders', ['channel' => 'direct']);
        $this->assertDatabaseHas('orders', ['channel' => 'agent']);
        $this->assertDatabaseHas('settlements', ['status' => 'paid']);
        $this->assertDatabaseHas('settlements', ['snapshot->source' => 'demo_data']);
    }

    private function assertDemoCounts(): void
    {
        $this->assertDatabaseCount('agents', 12);
        $this->assertDatabaseCount('direct_sales_sources', 3);
        $this->assertDatabaseCount('customers', 144);
        $this->assertDatabaseCount('customer_contacts', 144);
        $this->assertDatabaseCount('customer_identity_documents', 144);
        $this->assertDatabaseCount('appointments', 144);
        $this->assertDatabaseCount('orders', 144);
        $this->assertDatabaseCount('order_commissions', 100);
    }
}
