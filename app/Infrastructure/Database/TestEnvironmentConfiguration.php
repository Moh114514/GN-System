<?php

namespace App\Infrastructure\Database;

use Dotenv\Dotenv;
use RuntimeException;

final class TestEnvironmentConfiguration
{
    /**
     * @param  array<string, string>  $values
     */
    private function __construct(private readonly array $values) {}

    public static function fromFile(string $path): self
    {
        if (! is_file($path)) {
            throw new RuntimeException(
                '缺少 .env.testing。请先复制 .env.testing.example，禁止回退到开发环境配置。',
            );
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException('无法读取 .env.testing，测试已终止。');
        }

        /** @var array<string, string|null> $parsed */
        $parsed = Dotenv::parse($contents);
        $values = array_map(
            static fn (?string $value): string => $value ?? '',
            $parsed,
        );

        $configuration = new self($values);
        $configuration->assertSafe();

        return $configuration;
    }

    /**
     * @return array<string, string>
     */
    public function processEnvironment(): array
    {
        return [
            ...$this->values,
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'pgsql',
            'DB_DATABASE' => $this->values['DB_DATABASE'],
            'DB_URL' => '',
            'GN_TEST_DATABASE_CONFIRMED' => '1',
        ];
    }

    private function assertSafe(): void
    {
        $environment = $this->values['APP_ENV'] ?? '';
        $connection = $this->values['DB_CONNECTION'] ?? '';
        $database = $this->values['DB_DATABASE'] ?? '';

        if ($environment !== 'testing') {
            throw new RuntimeException(
                '测试环境配置无效：APP_ENV 必须为 testing，当前值为 '.($environment ?: '[未配置]').'。',
            );
        }

        if ($connection !== 'pgsql') {
            throw new RuntimeException(
                '测试环境配置无效：DB_CONNECTION 必须为 pgsql，当前值为 '.($connection ?: '[未配置]').'。',
            );
        }

        if (! ($database === 'gn_system_test' || preg_match('/_(?:test|testing)$/i', $database) === 1)) {
            throw new RuntimeException(
                '测试环境配置无效：DB_DATABASE 必须为 gn_system_test 或以 _test/_testing 结尾，当前值为 '
                .($database ?: '[未配置]').'。',
            );
        }
    }
}
