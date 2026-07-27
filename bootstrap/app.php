<?php

use App\Infrastructure\Health\OperationsHealthController;
use App\Infrastructure\Health\ReadinessController;
use App\Modules\Auth\Http\Middleware\EnsureSuperAdmin;
use App\Modules\Auth\Http\Middleware\RequireTwoFactorForSuperAdmin;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::get('/health', ReadinessController::class)->name('health');
            Route::get('/health/operations', OperationsHealthController::class)
                ->name('health.operations');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'super-admin' => EnsureSuperAdmin::class,
            'super-admin.2fa' => RequireTwoFactorForSuperAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        Integration::handles($exceptions);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
