<?php

namespace App\Modules\DataImport\Application\Services;

use App\Modules\Audit\Application\Contracts\AuditRecorder;
use Database\Seeders\PhaseTwoReferenceDataSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final readonly class UatDataResetService
{
    /** @var list<string> */
    private const BUSINESS_TABLES = [
        'import_issues', 'import_rows', 'import_files', 'import_batches',
        'reminder_events', 'reminders',
        'settlement_grade_suggestions', 'settlement_documents', 'settlement_runs',
        'settlement_items', 'settlements',
        'order_commissions', 'followup_records', 'appointments', 'orders',
        'customer_status_histories', 'customer_identity_documents', 'customer_contacts', 'customers',
        'customer_number_sequences',
        'agent_commission_overrides', 'agent_grade_assignments', 'agent_contracts', 'agents',
        'activity_log', 'report_exports',
        'jobs', 'job_batches', 'failed_jobs', 'cache', 'cache_locks', 'sessions', 'password_reset_tokens',
    ];

    /** @var list<string> */
    private const PRIVATE_BUSINESS_DIRECTORIES = ['imports', 'reports', 'settlements'];

    public function __construct(private AuditRecorder $audit) {}

    public function resetBusinessData(string $operator): void
    {
        $operator = trim($operator);
        if ($operator === '') {
            throw new RuntimeException('An --operator identifier is required for the audit record.');
        }
        if (mb_strlen($operator) > 128) {
            throw new RuntimeException('The operator identifier must not exceed 128 characters.');
        }

        $this->assertUat();
        $this->privateStorageRoot();

        try {
            DB::transaction(function (): void {
                $tables = array_values(array_filter(
                    self::BUSINESS_TABLES,
                    static fn (string $table): bool => Schema::hasTable($table),
                ));
                if ($tables === []) {
                    throw new RuntimeException('No resettable business tables were found.');
                }

                DB::statement('TRUNCATE TABLE '.implode(', ', $tables).' RESTART IDENTITY CASCADE');
                $exitCode = Artisan::call('db:seed', [
                    '--class' => PhaseTwoReferenceDataSeeder::class,
                    '--force' => true,
                ]);
                if ($exitCode !== 0) {
                    throw new RuntimeException('基础参考数据恢复失败。');
                }
            });
        } catch (Throwable $exception) {
            $this->recordFailure($operator, 'database_reset', $exception);

            throw $exception;
        }

        try {
            $this->recordPhase($operator, 'database_reset_completed', 'Database reset completed.');
        } catch (Throwable $exception) {
            $this->recordFailure($operator, 'database_reset_audit', $exception);

            throw $exception;
        }

        try {
            $this->clearPrivateBusinessFiles();
        } catch (Throwable $exception) {
            $this->recordFailure($operator, 'private_files_cleanup', $exception);

            throw $exception;
        }

        try {
            $this->recordPhase($operator, 'private_files_cleanup_completed', 'Private business files cleanup completed.');
            $this->recordPhase($operator, 'reset_completed', 'UAT business data reset completed.');
        } catch (Throwable $exception) {
            $this->recordFailure($operator, 'completion_audit', $exception);

            throw $exception;
        }
    }

    private function assertUat(): void
    {
        if (! app()->environment('production')) {
            throw new RuntimeException('UAT reset requires APP_ENV=production as configured by .env.uat.');
        }

        $appUrl = (string) config('app.url');
        $host = (string) (parse_url($appUrl, PHP_URL_HOST) ?: '');
        if ($host === '' || ! str_contains(strtolower($host), 'uat')) {
            throw new RuntimeException('APP_URL must identify the UAT host.');
        }

        $connection = (string) config('database.default');
        $configuredDatabase = config("database.connections.{$connection}.database");
        if ($connection !== 'pgsql' || $configuredDatabase !== 'gn_system_uat') {
            throw new RuntimeException('The configured database must be the PostgreSQL database gn_system_uat.');
        }

        $actualDatabase = DB::connection($connection)->scalar('select current_database()');
        if ($actualDatabase !== 'gn_system_uat') {
            throw new RuntimeException('PostgreSQL current_database() is not gn_system_uat.');
        }
    }

    private function privateStorageRoot(): string
    {
        $root = realpath((string) config('filesystems.disks.local.root'));
        $expectedRoot = realpath(storage_path('app/private'));
        if ($root === false || $expectedRoot === false || $root !== $expectedRoot) {
            throw new RuntimeException('The local private storage root is not the protected UAT storage path.');
        }

        return $root;
    }

    private function clearPrivateBusinessFiles(): void
    {
        $root = $this->privateStorageRoot();
        $disk = Storage::disk('local');
        foreach (self::PRIVATE_BUSINESS_DIRECTORIES as $directory) {
            $target = realpath($root.DIRECTORY_SEPARATOR.$directory);
            if ($target === false) {
                continue;
            }
            if (dirname($target) !== $root) {
                throw new RuntimeException("Private business directory [{$directory}] escaped the protected storage root.");
            }

            if (! $disk->deleteDirectory($directory)) {
                throw new RuntimeException("Unable to clear private business directory [{$directory}].");
            }
        }
    }

    private function recordPhase(string $operator, string $event, string $description): void
    {
        $this->audit->record(
            description: $description,
            properties: [
                'environment' => 'uat',
                'database' => 'gn_system_uat',
                'scope' => 'business-data',
                'tables' => self::BUSINESS_TABLES,
                'operator' => $operator,
            ],
            logName: 'uat-operations',
            event: $event,
        );
    }

    private function recordFailure(string $operator, string $phase, Throwable $exception): void
    {
        try {
            $this->audit->record(
                description: 'UAT business data reset failed',
                properties: [
                    'environment' => 'uat',
                    'database' => 'gn_system_uat',
                    'scope' => 'business-data',
                    'operator' => $operator,
                    'phase' => $phase,
                    'error' => $exception->getMessage(),
                ],
                logName: 'uat-operations',
                event: 'reset_failed',
            );
        } catch (Throwable) {
            // Preserve the original reset failure if the audit backend is unavailable.
        }
    }
}
