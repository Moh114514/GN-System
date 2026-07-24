<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
