<?php

namespace Tests\Unit\Infrastructure\Time;

use App\Infrastructure\Time\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class BusinessClockTest extends TestCase
{
    protected function tearDown(): void
    {
        config([
            'app.deployment_environment' => 'testing',
            'app.time_travel_enabled' => true,
        ]);
        Cache::forget('gn:test-clock:enabled');
        Cache::forget('gn:test-clock:now');
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

        $clock->set(CarbonImmutable::parse('2026-09-10 10:00:00', 'Asia/Shanghai'));

        $this->assertTrue($clock->isActive());
        $this->assertSame('2026-09-10 10:00', $clock->now()->format('Y-m-d H:i'));
        $this->assertSame('2026-10-10 10:00', $clock->shift('month')->format('Y-m-d H:i'));
        $this->assertSame('2026-10-10T10:00:00+08:00', (string) Cache::get('gn:test-clock:now'));

        $clock->disable();

        $this->assertFalse($clock->isActive());
        $this->assertNull(Cache::get('gn:test-clock:now'));
    }

    public function test_production_never_activates_or_reads_simulated_time(): void
    {
        config([
            'app.deployment_environment' => 'production',
            'app.time_travel_enabled' => true,
        ]);
        CarbonImmutable::setTestNow('2026-08-18 10:15:00');
        Cache::put('gn:test-clock:enabled', true);
        Cache::put('gn:test-clock:now', '2099-01-01T00:00:00+08:00');
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
        Cache::put('gn:test-clock:enabled', true);
        Cache::put('gn:test-clock:now', 'not-a-timestamp');
        $clock = app(BusinessClock::class);

        $this->assertFalse($clock->isActive());
        $this->assertSame('2026-08-18 10:15', $clock->now()->format('Y-m-d H:i'));
    }
}
