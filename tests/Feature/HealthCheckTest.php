<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_readiness_check_returns_ok_when_dependencies_are_available(): void
    {
        $this->getJson('/health')
            ->assertOk()
            ->assertExactJson(['status' => 'ok']);
    }

    public function test_readiness_check_does_not_leak_dependency_errors(): void
    {
        Redis::shouldReceive('connection')
            ->once()
            ->andThrow(new RuntimeException('redis://secret-password@redis:6379'));

        $response = $this->getJson('/health');

        $response
            ->assertServiceUnavailable()
            ->assertExactJson(['status' => 'unavailable'])
            ->assertDontSee('secret-password');
    }

    public function test_operations_check_returns_ok_for_fresh_heartbeats(): void
    {
        Cache::put('gn-system:queue-heartbeat', now()->toIso8601String(), now()->addMinutes(5));
        Cache::put('gn-system:scheduler-heartbeat', now()->toIso8601String(), now()->addMinutes(5));

        $this->getJson('/health/operations')
            ->assertOk()
            ->assertExactJson([
                'status' => 'ok',
                'components' => [
                    'queue' => 'ok',
                    'scheduler' => 'ok',
                ],
            ]);
    }

    public function test_operations_check_returns_unavailable_for_a_stale_heartbeat(): void
    {
        Carbon::setTestNow(now());

        Cache::put(
            'gn-system:queue-heartbeat',
            now()->subMinutes(4)->toIso8601String(),
            now()->addMinutes(5),
        );
        Cache::put('gn-system:scheduler-heartbeat', now()->toIso8601String(), now()->addMinutes(5));

        $this->getJson('/health/operations')
            ->assertServiceUnavailable()
            ->assertExactJson([
                'status' => 'unavailable',
                'components' => [
                    'queue' => 'unavailable',
                    'scheduler' => 'ok',
                ],
            ]);
    }

    public function test_operations_check_does_not_leak_cache_errors(): void
    {
        Cache::put(
            'gn-system:queue-heartbeat',
            'redis://secret-password@redis:6379',
            now()->addMinutes(5),
        );

        $this->getJson('/health/operations')
            ->assertServiceUnavailable()
            ->assertExactJson([
                'status' => 'unavailable',
                'components' => [
                    'queue' => 'unavailable',
                    'scheduler' => 'unavailable',
                ],
            ])
            ->assertDontSee('secret-password');
    }
}
