<?php

namespace App\Modules\Auth\Console;

use App\Modules\Auth\Application\Services\AdminMaintenanceService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use InvalidArgumentException;

final class ResetAdminPasswordCommand extends Command
{
    protected $signature = 'app:reset-admin-password {admin : Super administrator ID or email} {--reason= : Required reason for the change} {--operator= : Required operator or ticket identifier} {--clear-2fa : Also clear the administrator 2FA enrollment}';

    protected $description = 'Reset a super administrator password without accepting it as a command argument';

    public function __construct(private readonly AdminMaintenanceService $maintenance)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $reason = AdminCommandSupport::reason($this);
            $operator = AdminCommandSupport::operator($this);
            $password = (string) $this->secret('New administrator password');
            $confirmation = (string) $this->secret('Confirm administrator password');
            $validator = Validator::make(
                ['password' => $password, 'confirmation' => $confirmation],
                ['password' => ['required', 'same:confirmation', Password::min(12)->mixedCase()->letters()->numbers()->symbols()]],
            );
            if ($validator->fails()) {
                foreach ($validator->errors()->all() as $error) {
                    $this->error($error);
                }

                return self::FAILURE;
            }

            $clearTwoFactor = (bool) $this->option('clear-2fa');
            $this->maintenance->resetPassword(
                (string) $this->argument('admin'),
                $password,
                $clearTwoFactor,
                $reason,
                $operator,
            );

            $this->info('Administrator password reset and existing sessions invalidated.');

            return self::SUCCESS;
        } catch (ModelNotFoundException) {
            $this->error('Super administrator not found.');
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());
        }

        return self::FAILURE;
    }
}
