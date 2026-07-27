<?php

namespace Tests\Unit\Infrastructure\Database;

use App\Infrastructure\Database\TestEnvironmentConfiguration;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class TestEnvironmentConfigurationTest extends TestCase
{
    public function test_explicit_safe_testing_configuration_is_loaded(): void
    {
        $path = $this->temporaryEnvironmentFile(<<<'ENV'
APP_ENV=testing
DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=gn_system_test
DB_USERNAME=gn_system
ENV);

        $environment = TestEnvironmentConfiguration::fromFile($path)->processEnvironment();

        $this->assertSame('testing', $environment['APP_ENV']);
        $this->assertSame('pgsql', $environment['DB_CONNECTION']);
        $this->assertSame('gn_system_test', $environment['DB_DATABASE']);
        $this->assertSame('', $environment['DB_URL']);
        $this->assertSame('1', $environment['GN_TEST_DATABASE_CONFIRMED']);
    }

    public function test_missing_testing_configuration_fails_instead_of_falling_back(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('禁止回退到开发环境配置');

        TestEnvironmentConfiguration::fromFile(
            sys_get_temp_dir().DIRECTORY_SEPARATOR.'gn-system-missing-'.bin2hex(random_bytes(8)),
        );
    }

    public function test_development_database_name_is_rejected(): void
    {
        $path = $this->temporaryEnvironmentFile(<<<'ENV'
APP_ENV=testing
DB_CONNECTION=pgsql
DB_DATABASE=gn_system
ENV);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('当前值为 gn_system');

        TestEnvironmentConfiguration::fromFile($path);
    }

    public function test_error_does_not_include_database_password(): void
    {
        $password = 'never-print-this-password';
        $path = $this->temporaryEnvironmentFile(<<<ENV
APP_ENV=testing
DB_CONNECTION=pgsql
DB_DATABASE=gn_system
DB_PASSWORD={$password}
ENV);

        try {
            TestEnvironmentConfiguration::fromFile($path);
            $this->fail('The unsafe database should have been rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringNotContainsString($password, $exception->getMessage());
        }
    }

    private function temporaryEnvironmentFile(string $contents): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'gn-system-testing-'.bin2hex(random_bytes(8));
        file_put_contents($path, $contents);
        $this->filesToDelete[] = $path;

        return $path;
    }

    /**
     * @var list<string>
     */
    private array $filesToDelete = [];

    protected function tearDown(): void
    {
        foreach ($this->filesToDelete as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }
}
