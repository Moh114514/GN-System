<?php

namespace App\Infrastructure\Database;

use RuntimeException;

final class DestructiveDatabaseCommandGuard
{
    /**
     * @var list<string>
     */
    private const GUARDED_COMMANDS = [
        'migrate:fresh',
        'migrate:reset',
        'db:wipe',
    ];

    public function guards(string $command): bool
    {
        return in_array($command, self::GUARDED_COMMANDS, true);
    }

    public function assertTestingEnvironmentConfigured(
        string $environment,
        string $connection,
        ?string $configuredDatabase,
        bool $testingEnvironmentFileExists,
    ): void {
        if ($environment !== 'testing') {
            return;
        }

        $reason = match (true) {
            ! $testingEnvironmentFileExists => '缺少 .env.testing，禁止回退到 .env 继续执行。',
            $connection !== 'pgsql' => 'testing 环境必须使用明确的 pgsql 测试连接。',
            ! $this->isTestDatabase($configuredDatabase) => 'testing 环境的数据库名称不符合测试库命名规则。',
            default => null,
        };

        if ($reason === null) {
            return;
        }

        throw new RuntimeException(implode(PHP_EOL, [
            '测试数据库环境配置不安全，应用已停止启动。',
            "当前环境：{$environment}",
            "当前连接：{$connection}",
            '当前数据库：'.($configuredDatabase ?: '[未配置]'),
            "拒绝原因：{$reason}",
            '请复制 .env.testing.example 为 .env.testing，配置独立测试库后使用 composer test。',
        ]));
    }

    public function assertAllowed(
        string $command,
        string $environment,
        string $connection,
        ?string $configuredDatabase,
        ?string $actualDatabase,
        bool $testingEnvironmentFileExists,
        bool $safeTestEntryPoint,
    ): void {
        if (! $this->guards($command)) {
            return;
        }

        $reason = match (true) {
            $environment !== 'testing' => '破坏性数据库命令只能在 testing 环境中执行。',
            ! $testingEnvironmentFileExists => '缺少 .env.testing，禁止回退到 .env 继续执行。',
            ! $safeTestEntryPoint => '命令未通过项目安全测试入口启动。',
            $connection !== 'pgsql' => '当前数据库连接不是明确允许的 pgsql 测试连接。',
            ! $this->isTestDatabase($configuredDatabase) => '配置的数据库名称不符合测试库命名规则。',
            ! $this->isTestDatabase($actualDatabase) => 'PostgreSQL 实际连接的数据库名称不符合测试库命名规则。',
            $configuredDatabase !== $actualDatabase => '配置数据库与 PostgreSQL 实际连接数据库不一致。',
            default => null,
        };

        if ($reason === null) {
            return;
        }

        throw new RuntimeException(implode(PHP_EOL, [
            "拒绝执行破坏性数据库命令 [{$command}]。",
            "当前环境：{$environment}",
            "当前连接：{$connection}",
            '配置数据库：'.($configuredDatabase ?: '[未配置]'),
            '实际数据库：'.($actualDatabase ?: '[无法确认]'),
            "拒绝原因：{$reason}",
            '请复制 .env.testing.example 为 .env.testing，确认 DB_DATABASE 为 gn_system_test（或以 _test/_testing 结尾），然后使用 composer test。',
        ]));
    }

    private function isTestDatabase(?string $database): bool
    {
        if ($database === null || $database === '') {
            return false;
        }

        return $database === 'gn_system_test'
            || preg_match('/_(?:test|testing)$/i', $database) === 1;
    }
}
