<?php

namespace Tests\Feature;

use Database\Seeders\DevelopmentScenarioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DevelopmentScenarioSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_development_scenario_covers_business_states_and_is_idempotent(): void
    {
        $this->seed(DevelopmentScenarioSeeder::class);

        $this->assertDatabaseCount('users', 10);
        $this->assertDatabaseCount('business_groups', 2);
        $this->assertDatabaseCount('business_group_memberships', 7);
        $this->assertDatabaseCount('agents', 15);
        $this->assertDatabaseCount('customers', 200);
        $this->assertDatabaseCount('appointments', 250);
        $this->assertDatabaseCount('orders', 250);
        $this->assertDatabaseCount('order_commissions', 215);
        $this->assertDatabaseCount('followup_records', 220);
        $this->assertDatabaseCount('reminders', 70);
        $this->assertDatabaseCount('import_batches', 10);
        $this->assertDatabaseCount('import_files', 10);
        $this->assertDatabaseCount('import_rows', 30);
        $this->assertDatabaseCount('activity_log', 120);

        $this->assertDatabaseHas('customers', ['code' => 'DEV-CUST-0001']);
        $this->assertDatabaseHas('customers', ['code' => 'DEV-CUST-0017', 'owner_id' => null]);
        $this->assertDatabaseHas('agents', ['code' => 'DEV08-GT', 'cooperation_status' => 'paused']);
        $this->assertDatabaseHas('agents', ['code' => 'DEV14-JG', 'cooperation_status' => 'terminated']);
        $this->assertDatabaseHas('orders', ['status' => 'pending']);
        $this->assertDatabaseHas('orders', ['status' => 'cancelled']);
        $this->assertDatabaseHas('orders', ['amount_krw' => 0]);
        $this->assertDatabaseHas('orders', ['amount_krw' => 99999999999]);
        $this->assertDatabaseHas('settlement_runs', ['status' => 'partial_failed']);
        $this->assertDatabaseHas('reminders', ['status' => 'snoozed']);
        $this->assertDatabaseHas('import_batches', ['status' => 'needs_review']);
        $this->assertDatabaseHas('import_batches', ['status' => 'failed']);
        $this->assertDatabaseHas('activity_log', ['batch_uuid' => '00000000-0000-4000-8000-000000000099']);

        $this->assertDatabaseCount('customers', 200);
        $this->assertDatabaseCount('orders', 250);
        $this->assertGreaterThanOrEqual(40, DB::table('settlements')->count());
        $this->assertDatabaseCount('settlement_runs', 5);

        $this->seed(DevelopmentScenarioSeeder::class);

        $this->assertDatabaseCount('agents', 15);
        $this->assertDatabaseCount('customers', 200);
        $this->assertDatabaseCount('orders', 250);
        $this->assertDatabaseCount('order_commissions', 215);
        $this->assertDatabaseCount('followup_records', 220);
        $this->assertDatabaseCount('reminders', 70);
        $this->assertDatabaseCount('import_batches', 10);
        $this->assertDatabaseCount('activity_log', 120);
    }
}
