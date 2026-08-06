<?php

namespace App\Modules\Auth\Console;

use App\Modules\Auth\Application\Services\AdminMaintenanceService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;

final class DisableAdminCommand extends Command
{
    protected $signature = 'app:disable-admin {admin : Super administrator ID or email} {--reason= : Required reason for the change} {--operator= : Required operator or ticket identifier}';

    protected $description = 'Disable a super administrator and invalidate current sessions';

    public function __construct(private readonly AdminMaintenanceService $maintenance)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $reason = AdminCommandSupport::reason($this);
            $operator = AdminCommandSupport::operator($this);
            $this->maintenance->disable((string) $this->argument('admin'), $reason, $operator);

            $this->info('Administrator disabled and existing sessions invalidated.');

            return self::SUCCESS;
        } catch (ModelNotFoundException) {
            $this->error('Super administrator not found.');
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());
        }

        return self::FAILURE;
    }
}
