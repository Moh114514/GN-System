<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class DeployScriptSafetyTest extends TestCase
{
    public function test_uat_reset_script_has_host_level_guards_and_health_report(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2).'\\deploy\\reset-uat.sh');

        self::assertIsString($script);
        self::assertStringContainsString("readonly UAT_ROOT='/srv/gn-system'", $script);
        self::assertStringContainsString("readonly COMPOSE_PROJECT='gn-system-uat'", $script);
        self::assertStringContainsString("readonly UAT_DATABASE='gn_system_uat'", $script);
        self::assertStringContainsString("readonly CONFIRMATION='RESET gn_system_uat'", $script);
        self::assertStringContainsString('if [[ "$current_dir" == \'/srv/gn-system/production\'', $script);
        self::assertStringContainsString('if [[ "$database_value" == \'gn_system\'', $script);
        self::assertStringContainsString('if [[ "${app_url,,}" != *uat* ]]', $script);
        self::assertStringContainsString('backup:run', $script);
        self::assertStringContainsString('psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Atqc', $script);
        self::assertStringContainsString('stop queue scheduler', $script);
        self::assertStringContainsString('up -d --force-recreate --remove-orphans', $script);
        self::assertStringContainsString('FLUSHALL', $script);
        self::assertStringContainsString('/health/operations', $script);
        self::assertStringNotContainsString('/var/run/docker.sock', $script);
    }

    public function test_config_reload_script_validates_and_never_prints_secret_values(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2).'\\deploy\\reload-config.sh');

        self::assertIsString($script);
        self::assertStringContainsString("if [[ \"$(stat -c '%a'", $script);
        self::assertStringContainsString('config --quiet', $script);
        self::assertStringContainsString('--force-recreate --no-deps app queue scheduler', $script);
        self::assertStringContainsString('optimize:clear', $script);
        self::assertStringContainsString('config:cache', $script);
        self::assertStringContainsString('psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Atqc', $script);
        self::assertStringContainsString('/up /health /health/operations', $script);
        self::assertStringNotContainsString('printf \'DB_PASSWORD=', $script);
        self::assertStringNotContainsString('printf \'REDIS_PASSWORD=', $script);
        self::assertStringNotContainsString('printf \'MAIL_PASSWORD=', $script);
        self::assertStringNotContainsString('printf \'BACKUP_ARCHIVE_PASSWORD=', $script);
        self::assertStringNotContainsString('printf \'DINGTALK_SECRET=', $script);
    }

    public function test_application_uat_reset_has_an_explicit_allowlist_and_uat_database_checks(): void
    {
        $command = file_get_contents(dirname(__DIR__, 2).'\\app\\Modules\\DataImport\\Console\\ResetUatDataCommand.php');

        self::assertIsString($command);
        self::assertStringContainsString("app()->environment('production')", $command);
        self::assertStringContainsString("config('database.default')", $command);
        self::assertStringContainsString('select current_database()', $command);
        self::assertStringContainsString("'activity_log'", $command);
        self::assertStringContainsString("'report_exports'", $command);
        self::assertStringContainsString("'imports', 'reports', 'settlements'", $command);
        self::assertStringContainsString("option('business-data')", $command);
        self::assertStringContainsString("option('operator')", $command);
        self::assertStringContainsString('deleteDirectory', $command);
        self::assertStringNotContainsString("'report_saved_queries'", $command);
        self::assertStringNotContainsString('migrate:fresh', $command);
        self::assertStringNotContainsString('docker.sock', $command);
    }
}
