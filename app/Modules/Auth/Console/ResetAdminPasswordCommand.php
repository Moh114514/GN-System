<?php

namespace App\Modules\Auth\Console;

use App\Models\User;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use InvalidArgumentException;

final class ResetAdminPasswordCommand extends Command
{
    protected $signature = 'app:reset-admin-password {admin : Super administrator ID or email} {--reason= : Required reason for the change} {--operator= : Required operator or ticket identifier} {--clear-2fa : Also clear the administrator 2FA enrollment}';

    protected $description = 'Reset a super administrator password without accepting it as a command argument';

    public function __construct(private readonly AuditRecorder $audit)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $reason = AdminCommandSupport::reason($this);
            $operator = AdminCommandSupport::operator($this);
            $admin = AdminCommandSupport::resolve((string) $this->argument('admin'));
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
            DB::transaction(function () use ($admin, $password, $reason, $operator, $clearTwoFactor): void {
                $locked = User::query()->whereKey($admin->id)->lockForUpdate()->firstOrFail();
                $attributes = [
                    'password' => Hash::make($password),
                    'session_version' => $locked->session_version + 1,
                    'remember_token' => null,
                ];
                if ($clearTwoFactor) {
                    $attributes += [
                        'two_factor_secret' => null,
                        'two_factor_recovery_codes' => null,
                        'two_factor_confirmed_at' => null,
                    ];
                }
                $locked->forceFill($attributes)->save();
                if (Schema::hasTable('sessions')) {
                    DB::table('sessions')->where('user_id', $locked->id)->delete();
                }
                $this->audit->record(
                    description: 'Super administrator password reset',
                    properties: ['user_id' => $locked->id, 'reason' => $reason, 'clear_2fa' => $clearTwoFactor, 'operator' => $operator],
                    subject: $locked,
                    logName: 'security',
                    event: 'admin_password_reset',
                );
            });

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
