<?php

namespace Tests\Unit\Infrastructure\Time;

use App\Infrastructure\Time\BusinessClock;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BusinessClockTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        config([
            'app.deployment_environment' => 'testing',
            'app.time_travel_enabled' => true,
        ]);
        DB::table('business_clock_states')->delete();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_business_time_is_shared_and_can_be_advanced(): void
    {
        config([
            'app.deployment_environment' => 'testing',
            'app.time_travel_enabled' => true,
        ]);
        $clock = app(BusinessClock::class);
        $actor = User::factory()->create();

        $clock->set(CarbonImmutable::parse('2026-09-10 10:00:00', 'Asia/Shanghai'), $actor->id);

        $this->assertTrue($clock->isActive());
        $this->assertSame('2026-09-10 10:00', $clock->now()->format('Y-m-d H:i'));
        $this->assertSame('2026-10-10 10:00', $clock->shift('month', $actor->id)->format('Y-m-d H:i'));
        $this->assertDatabaseHas('business_clock_states', [
            'id' => 1,
            'enabled' => true,
            'mode' => 'frozen',
            'simulated_at' => '2026-10-10 10:00:00',
            'changed_by' => $actor->id,
        ]);

        $clock->disable($actor->id);

        $this->assertFalse($clock->isActive());
        $this->assertDatabaseHas('business_clock_states', [
            'id' => 1,
            'enabled' => false,
            'simulated_at' => null,
            'mode' => 'real',
            'changed_by' => $actor->id,
        ]);
    }

    public function test_production_never_activates_or_reads_simulated_time(): void
    {
        config([
            'app.deployment_environment' => 'production',
            'app.time_travel_enabled' => true,
        ]);
        CarbonImmutable::setTestNow('2026-08-18 10:15:00');
        DB::table('business_clock_states')->updateOrInsert(['id' => 1], [
            'enabled' => true,
            'simulated_at' => '2099-01-01 00:00:00',
            'mode' => 'frozen',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $clock = app(BusinessClock::class);

        $this->assertFalse($clock->isAvailable());
        $this->assertFalse($clock->isActive());
        $this->assertSame('2026-08-18 10:15', $clock->now()->format('Y-m-d H:i'));
    }

    public function test_invalid_active_state_uses_real_time_without_breaking_business_pages(): void
    {
        config([
            'app.deployment_environment' => 'testing',
            'app.time_travel_enabled' => true,
        ]);
        CarbonImmutable::setTestNow('2026-08-18 10:15:00');
        DB::table('business_clock_states')->updateOrInsert(['id' => 1], [
            'enabled' => true,
            'simulated_at' => null,
            'mode' => 'frozen',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $clock = app(BusinessClock::class);

        $this->assertFalse($clock->isActive());
        $this->assertSame('2026-08-18 10:15', $clock->now()->format('Y-m-d H:i'));
    }

    public function test_business_time_survives_cache_flush_and_a_fresh_clock_instance(): void
    {
        config([
            'app.deployment_environment' => 'testing',
            'app.time_travel_enabled' => true,
        ]);
        $clock = app(BusinessClock::class);
        $clock->set(CarbonImmutable::parse('2027-02-10 09:00:00', 'Asia/Shanghai'));

        cache()->flush();
        app()->forgetInstance(BusinessClock::class);
        $freshClock = app(BusinessClock::class);

        $this->assertTrue($freshClock->isActive());
        $this->assertSame('2027-02-10 09:00', $freshClock->now()->format('Y-m-d H:i'));
    }
}
