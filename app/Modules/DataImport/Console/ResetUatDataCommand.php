<?php

namespace App\Modules\DataImport\Console;

use App\Modules\Audit\Application\Contracts\AuditRecorder;
use Database\Seeders\PhaseTwoReferenceDataSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class ResetUatDataCommand extends Command
{
    protected $signature = 'app:reset-uat-data
        {--business-data : Reset UAT business data while preserving users and reference configuration}
        {--confirm= : Required confirmation phrase for non-interactive execution}
        {--operator= : Required operator or ticket identifier for the audit record}';

    protected $description = 'Reset UAT business data after strict environment and database checks';

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

    public function __construct(private readonly AuditRecorder $audit)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            if (! (bool) $this->option('business-data')) {
                throw new RuntimeException('The --business-data scope must be provided explicitly.');
            }
            $this->assertUat();
            $operator = $this->operator();
            $this->privateStorageRoot();
            $confirmation = (string) $this->option('confirm');
            if ($confirmation === '') {
                $confirmation = (string) $this->ask('Type RESET gn_system_uat to continue');
            }
            if ($confirmation !== 'RESET gn_system_uat') {
                throw new RuntimeException('Confirmation must exactly equal RESET gn_system_uat.');
            }

            DB::transaction(function (): void {
                $tables = array_values(array_filter(
                    self::BUSINESS_TABLES,
                    static fn (string $table): bool => Schema::hasTable($table),
                ));
                if ($tables === []) {
                    throw new RuntimeException('No resettable business tables were found.');
                }

                DB::statement('TRUNCATE TABLE '.implode(', ', $tables).' RESTART IDENTITY CASCADE');
                $this->call(PhaseTwoReferenceDataSeeder::class);
            });
            $this->clearPrivateBusinessFiles();

            $this->audit->record(
                description: 'UAT business data reset completed',
                properties: [
                    'environment' => 'uat',
                    'database' => 'gn_system_uat',
                    'scope' => 'business-data',
                    'tables' => self::BUSINESS_TABLES,
                    'operator' => $operator,
                ],
                logName: 'uat-operations',
                event: 'business_data_reset',
            );
            $this->info('UAT business data reset completed. Users and reference configuration were preserved.');

            return self::SUCCESS;
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
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

    private function operator(): string
    {
        $operator = trim((string) $this->option('operator'));
        if ($operator === '') {
            throw new RuntimeException('An --operator identifier is required for the audit record.');
        }
        if (mb_strlen($operator) > 128) {
            throw new RuntimeException('The operator identifier must not exceed 128 characters.');
        }

        return $operator;
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
}
