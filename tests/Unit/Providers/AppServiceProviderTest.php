<?php

namespace Tests\Unit\Providers;

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class AppServiceProviderTest extends TestCase
{
    public function test_production_urls_use_the_configured_app_origin(): void
    {
        config([
            'app.deployment_environment' => 'production',
            'app.url' => 'https://gncrm.example.test',
        ]);
        $this->app['request']->server->set('HTTPS', 'on');

        (new AppServiceProvider($this->app))->boot();

        $this->assertSame('https://gncrm.example.test/health', URL::to('/health'));
    }
}
