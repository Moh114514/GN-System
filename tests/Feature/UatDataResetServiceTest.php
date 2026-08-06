<?php

namespace Tests\Feature;

use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\DataImport\Application\Services\UatDataResetService;
use Closure;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class UatDataResetServiceTest extends TestCase
{
    public function test_empty_operator_is_rejected_before_environment_checks(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('operator');

        $this->service()->resetBusinessData('  ');
    }

    public function test_overlong_operator_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('128');

        $this->service()->resetBusinessData(str_repeat('x', 129));
    }

    public function test_non_production_environment_is_rejected(): void
    {
        $this->app->detectEnvironment(
            static fn (): string => 'testing',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('APP_ENV');

        $this->service()->resetBusinessData('test-operator');
    }

    public function test_non_uat_url_is_rejected(): void
    {
        $this->configureUat();

        config([
            'app.url' => 'https://example.test',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('UAT host');

        $this->service()->resetBusinessData('test-operator');
    }

    public function test_non_uat_database_configuration_is_rejected(): void
    {
        $this->configureUat();

        config([
            'app.url' => 'https://uat.example.test',
            'database.default' => 'pgsql',
            'database.connections.pgsql.database' => 'gn_system',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('gn_system_uat');

        $this->service()->resetBusinessData('test-operator');
    }

    public function test_actual_database_name_is_verified(): void
    {
        $this->configureUat();
        $this->mockCurrentDatabase('gn_system');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('current_database');

        $this->service()->resetBusinessData('test-operator');
    }

    public function test_private_storage_root_is_verified_before_reset(): void
    {
        $this->configureUat();
        config(['filesystems.disks.local.root' => storage_path('app/private/missing')]);
        $this->mockCurrentDatabase('gn_system_uat');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('storage path');

        $this->service()->resetBusinessData('test-operator');
    }

    public function test_nonzero_seeder_result_fails_the_database_phase_and_is_audited(): void
    {
        $this->configureUat();
        config(['filesystems.disks.local.root' => storage_path('app/private')]);

        // Schema resolves its builder through the real database manager, so set
        // up the Schema facade before replacing the DB facade with a mock.
        Schema::shouldReceive('hasTable')->andReturnTrue();

        $connection = Mockery::mock();
        $connection->shouldReceive('scalar')->once()->with('select current_database()')->andReturn('gn_system_uat');
        DB::shouldReceive('connection')->once()->with('pgsql')->andReturn($connection);
        DB::shouldReceive('transaction')->once()->andReturnUsing(
            static fn (Closure $callback): mixed => $callback(),
        );
        DB::shouldReceive('statement')->once();
        Artisan::shouldReceive('call')->once()->withArgs(static function (string $command, array $arguments): bool {
            return $command === 'db:seed'
                && $arguments['--force'] === true
                && $arguments['--class'] !== '';
        })->andReturn(1);

        $audit = Mockery::mock(AuditRecorder::class);
        $audit->shouldReceive('record')->once()->withArgs(static function (...$arguments): bool {
            return ($arguments[5] ?? null) === 'reset_failed'
                && (($arguments[1]['phase'] ?? null) === 'database_reset');
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('基础参考数据恢复失败');

        (new UatDataResetService($audit))->resetBusinessData('test-operator');
    }

    private function service(): UatDataResetService
    {
        return new UatDataResetService(Mockery::mock(AuditRecorder::class));
    }

    private function configureUat(): void
    {
        $this->app->detectEnvironment(
            static fn (): string => 'production',
        );

        config([
            'app.url' => 'https://uat.example.test',
            'database.default' => 'pgsql',
            'database.connections.pgsql.database' => 'gn_system_uat',
        ]);
    }

    private function mockCurrentDatabase(string $database): void
    {
        $connection = Mockery::mock();
        $connection->shouldReceive('scalar')
            ->once()
            ->with('select current_database()')
            ->andReturn($database);

        DB::shouldReceive('connection')
            ->once()
            ->with('pgsql')
            ->andReturn($connection);
    }
}
