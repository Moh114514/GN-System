<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class DeployScriptSafetyTest extends TestCase
{
    public function test_uat_reset_script_has_host_level_guards_and_health_report(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2).'/deploy/reset-uat.sh');

        self::assertIsString($script);
        self::assertStringContainsString("readonly UAT_ROOT='/srv/gn-system'", $script);
        self::assertStringContainsString("readonly REPOSITORY_DIR='/srv/gn-system/repository'", $script);
        self::assertStringContainsString('readonly ENV_FILE="$REPOSITORY_DIR/.env.uat"', $script);
        self::assertStringContainsString('readonly COMPOSE_FILE="$REPOSITORY_DIR/compose.production.yaml"', $script);
        self::assertStringContainsString('stat -c \'%a\' "$ENV_FILE"', $script);
        self::assertStringNotContainsString('$UAT_ROOT/$ENV_FILE', $script);
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
        self::assertStringContainsString('"$base_url/up"', $script);
        self::assertStringContainsString('"$base_url/health"', $script);
        self::assertStringContainsString('"$base_url/health/operations"', $script);
        self::assertStringContainsString('app:queue-heartbeat', $script);
        self::assertStringContainsString('app:scheduler-heartbeat', $script);
        self::assertStringContainsString('--cacert "$tls_cert_path"', $script);
        self::assertStringContainsString('health_deadline=$((SECONDS + 180))', $script);
        self::assertStringNotContainsString('/var/run/docker.sock', $script);
    }

    public function test_config_reload_script_validates_and_never_prints_secret_values(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2).'/deploy/reload-config.sh');

        self::assertIsString($script);
        self::assertStringContainsString("if [[ \"$(stat -c '%a'", $script);
        self::assertStringContainsString('config --quiet', $script);
        self::assertStringContainsString('--force-recreate --no-deps app queue scheduler', $script);
        self::assertStringContainsString('optimize:clear', $script);
        self::assertStringContainsString('config:cache', $script);
        self::assertStringContainsString('psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Atqc', $script);
        self::assertStringContainsString('"$base_url/up"', $script);
        self::assertStringContainsString('"$base_url/health"', $script);
        self::assertStringContainsString('"$base_url/health/operations"', $script);
        self::assertStringContainsString("readonly REPOSITORY_DIR='/srv/gn-system/repository'", $script);
        self::assertStringContainsString('stat -c \'%a\' "$ENV_FILE"', $script);
        self::assertStringNotContainsString('$UAT_ROOT/$ENV_FILE', $script);
        self::assertStringContainsString('--cacert "$tls_cert_path"', $script);
        self::assertStringNotContainsString('printf \'DB_PASSWORD=', $script);
        self::assertStringNotContainsString('printf \'REDIS_PASSWORD=', $script);
        self::assertStringNotContainsString('printf \'MAIL_PASSWORD=', $script);
        self::assertStringNotContainsString('printf \'BACKUP_ARCHIVE_PASSWORD=', $script);
        self::assertStringNotContainsString('printf \'DINGTALK_SECRET=', $script);
    }

    public function test_application_uat_reset_has_an_explicit_allowlist_and_uat_database_checks(): void
    {
        $command = file_get_contents(dirname(__DIR__, 2).'/app/Modules/DataImport/Console/ResetUatDataCommand.php');
        $service = file_get_contents(dirname(__DIR__, 2).'/app/Modules/DataImport/Application/Services/UatDataResetService.php');

        self::assertIsString($command);
        self::assertIsString($service);
        self::assertStringContainsString("app()->environment('production')", $service);
        self::assertStringContainsString("config('database.default')", $service);
        self::assertStringContainsString('select current_database()', $service);
        self::assertStringContainsString("'activity_log'", $service);
        self::assertStringContainsString("'report_exports'", $service);
        self::assertStringContainsString("'imports', 'reports', 'settlements'", $service);
        self::assertStringContainsString("option('business-data')", $command);
        self::assertStringContainsString("option('operator')", $command);
        self::assertStringContainsString('deleteDirectory', $service);
        self::assertStringContainsString("Artisan::call('db:seed'", $service);
        self::assertStringContainsString("'database_reset_completed'", $service);
        self::assertStringContainsString("'private_files_cleanup_completed'", $service);
        self::assertStringContainsString("'reset_completed'", $service);
        self::assertStringContainsString("'reset_failed'", $service);
        self::assertStringContainsString("'phase' => \$phase", $service);
        self::assertStringNotContainsString("'report_saved_queries'", $service);
        self::assertStringNotContainsString('migrate:fresh', $service);
        self::assertStringNotContainsString('docker.sock', $service);
    }

    public function test_uat_scripts_have_valid_bash_syntax_on_unix_runners(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('bash is provided by the Unix CI runner.');
        }

        $root = dirname(__DIR__, 2);
        foreach (['deploy/deploy.sh', 'deploy/reset-uat.sh', 'deploy/reload-config.sh'] as $relativePath) {
            $output = [];
            $exitCode = 0;
            exec('bash -n '.escapeshellarg($root.'/'.$relativePath), $output, $exitCode);

            self::assertSame(0, $exitCode, implode(PHP_EOL, $output));
        }
    }

    public function test_release_deploy_migrates_and_clears_caches_before_starting_application(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2).'/deploy/deploy.sh');

        self::assertIsString($script);
        $migration = strpos($script, 'php artisan migrate --force --isolated --no-interaction');
        $clear = strpos($script, 'php artisan optimize:clear --no-interaction');
        $start = strpos($script, 'up -d --remove-orphans');

        self::assertIsInt($migration);
        self::assertIsInt($clear);
        self::assertIsInt($start);
        self::assertGreaterThan($migration, $clear);
        self::assertGreaterThan($clear, $start);
    }
}
