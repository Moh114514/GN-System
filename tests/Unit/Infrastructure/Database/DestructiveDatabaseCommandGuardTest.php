<?php

namespace Tests\Unit\Infrastructure\Database;

use App\Infrastructure\Database\DestructiveDatabaseCommandGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class DestructiveDatabaseCommandGuardTest extends TestCase
{
    private DestructiveDatabaseCommandGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->guard = new DestructiveDatabaseCommandGuard;
    }

    #[DataProvider('guardedCommands')]
    public function test_safe_testing_database_is_allowed(string $command): void
    {
        $this->guard->assertAllowed(
            command: $command,
            environment: 'testing',
            connection: 'pgsql',
            configuredDatabase: 'gn_system_test',
            actualDatabase: 'gn_system_test',
            testingEnvironmentFileExists: true,
            safeTestEntryPoint: true,
        );

        $this->addToAssertionCount(1);
    }

    #[DataProvider('guardedCommands')]
    public function test_development_database_is_rejected(string $command): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('当前环境：testing');
        $this->expectExceptionMessage('配置数据库：gn_system');
        $this->expectExceptionMessage('实际数据库：gn_system');

        $this->guard->assertAllowed(
            command: $command,
            environment: 'testing',
            connection: 'pgsql',
            configuredDatabase: 'gn_system',
            actualDatabase: 'gn_system',
            testingEnvironmentFileExists: true,
            safeTestEntryPoint: true,
        );
    }

    public function test_missing_testing_environment_file_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('缺少 .env.testing');

        $this->guard->assertAllowed(
            command: 'migrate:fresh',
            environment: 'testing',
            connection: 'pgsql',
            configuredDatabase: 'gn_system_test',
            actualDatabase: 'gn_system_test',
            testingEnvironmentFileExists: false,
            safeTestEntryPoint: true,
        );
    }

    public function test_missing_database_configuration_is_rejected_without_leaking_a_password(): void
    {
        $password = 'never-print-this-password';

        try {
            $this->guard->assertAllowed(
                command: 'db:wipe',
                environment: 'testing',
                connection: 'pgsql',
                configuredDatabase: null,
                actualDatabase: null,
                testingEnvironmentFileExists: true,
                safeTestEntryPoint: true,
            );

            $this->fail('The destructive command should have been rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('配置数据库：[未配置]', $exception->getMessage());
            $this->assertStringNotContainsString($password, $exception->getMessage());
        }
    }

    public function test_safe_database_still_requires_the_project_test_entry_point(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('命令未通过项目安全测试入口启动');

        $this->guard->assertAllowed(
            command: 'migrate:reset',
            environment: 'testing',
            connection: 'pgsql',
            configuredDatabase: 'gn_system_test',
            actualDatabase: 'gn_system_test',
            testingEnvironmentFileExists: true,
            safeTestEntryPoint: false,
        );
    }

    public function test_testing_application_boot_rejects_a_missing_environment_file(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('测试数据库环境配置不安全');
        $this->expectExceptionMessage('缺少 .env.testing');

        $this->guard->assertTestingEnvironmentConfigured(
            environment: 'testing',
            connection: 'pgsql',
            configuredDatabase: 'gn_system_test',
            testingEnvironmentFileExists: false,
        );
    }

    public function test_testing_application_boot_rejects_the_development_database(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('当前数据库：gn_system');

        $this->guard->assertTestingEnvironmentConfigured(
            environment: 'testing',
            connection: 'pgsql',
            configuredDatabase: 'gn_system',
            testingEnvironmentFileExists: true,
        );
    }

    public function test_non_testing_application_boot_is_not_affected(): void
    {
        $this->guard->assertTestingEnvironmentConfigured(
            environment: 'local',
            connection: 'pgsql',
            configuredDatabase: 'gn_system',
            testingEnvironmentFileExists: false,
        );

        $this->addToAssertionCount(1);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function guardedCommands(): array
    {
        return [
            'migrate fresh' => ['migrate:fresh'],
            'migrate reset' => ['migrate:reset'],
            'database wipe' => ['db:wipe'],
        ];
    }
}
