<?php

namespace App\Modules\Auth\Console;

use App\Modules\Auth\Application\Services\AdminMaintenanceService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;

final class EnableAdminCommand extends Command
{
    protected $signature = 'app:enable-admin {admin : Super administrator ID or email} {--reason= : Required reason for the change} {--operator= : Required operator or ticket identifier}';

    protected $description = 'Enable a previously disabled super administrator';

    public function __construct(private readonly AdminMaintenanceService $maintenance)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $reason = AdminCommandSupport::reason($this);
            $operator = AdminCommandSupport::operator($this);
            $this->maintenance->enable((string) $this->argument('admin'), $reason, $operator);

            $this->info('Administrator enabled.');

            return self::SUCCESS;
        } catch (ModelNotFoundException) {
            $this->error('Super administrator not found.');
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());
        }

        return self::FAILURE;
    }
}
