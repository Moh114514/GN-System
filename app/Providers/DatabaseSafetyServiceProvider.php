<?php

namespace App\Providers;

use App\Infrastructure\Database\DestructiveDatabaseCommandGuard;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Throwable;

final class DatabaseSafetyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DestructiveDatabaseCommandGuard::class);
    }

    public function boot(DestructiveDatabaseCommandGuard $guard): void
    {
        $defaultConnection = (string) config('database.default');
        $configuredDatabase = config("database.connections.{$defaultConnection}.database");

        $guard->assertTestingEnvironmentConfigured(
            environment: $this->app->environment(),
            connection: $defaultConnection,
            configuredDatabase: is_string($configuredDatabase) ? $configuredDatabase : null,
            testingEnvironmentFileExists: is_file($this->app->environmentPath().'/.env.testing'),
        );

        Event::listen(CommandStarting::class, function (CommandStarting $event) use ($guard): void {
            if (! $guard->guards($event->command)) {
                return;
            }

            $connection = $this->selectedConnection($event);
            $configuredDatabase = config("database.connections.{$connection}.database");
            $actualDatabase = $this->actualDatabase($connection);

            $guard->assertAllowed(
                command: $event->command,
                environment: $this->app->environment(),
                connection: $connection,
                configuredDatabase: is_string($configuredDatabase) ? $configuredDatabase : null,
                actualDatabase: $actualDatabase,
                testingEnvironmentFileExists: is_file($this->app->environmentPath().'/.env.testing'),
                safeTestEntryPoint: getenv('GN_TEST_DATABASE_CONFIRMED') === '1',
            );
        });
    }

    private function selectedConnection(CommandStarting $event): string
    {
        try {
            $selected = $event->input->getOption('database');

            if (is_string($selected) && $selected !== '') {
                return $selected;
            }
        } catch (Throwable) {
            // The guarded command may not define a database option in a future framework version.
        }

        return (string) config('database.default');
    }

    private function actualDatabase(string $connection): ?string
    {
        try {
            $database = DB::connection($connection)->scalar('select current_database()');

            return is_string($database) ? $database : null;
        } catch (Throwable) {
            return null;
        }
    }
}
