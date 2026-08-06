<?php

namespace App\Modules\DataImport\Console;

use App\Modules\DataImport\Application\Services\UatDataResetService;
use Illuminate\Console\Command;
use RuntimeException;

final class ResetUatDataCommand extends Command
{
    protected $signature = 'app:reset-uat-data
        {--business-data : Reset UAT business data while preserving users and reference configuration}
        {--confirm= : Required confirmation phrase for non-interactive execution}
        {--operator= : Required operator or ticket identifier for the audit record}';

    protected $description = 'Reset UAT business data after strict environment and database checks';

    public function __construct(private readonly UatDataResetService $reset)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            if (! (bool) $this->option('business-data')) {
                throw new RuntimeException('The --business-data scope must be provided explicitly.');
            }

            $operator = $this->operator();
            $confirmation = (string) $this->option('confirm');
            if ($confirmation === '') {
                $confirmation = (string) $this->ask('Type RESET gn_system_uat to continue');
            }
            if ($confirmation !== 'RESET gn_system_uat') {
                throw new RuntimeException('Confirmation must exactly equal RESET gn_system_uat.');
            }

            $this->reset->resetBusinessData($operator);
            $this->info('UAT business data reset completed. Users and reference configuration were preserved.');

            return self::SUCCESS;
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
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
}
