<?php

namespace App\Modules\Auth\Console;

use App\Models\User;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

final class DisableAdminCommand extends Command
{
    protected $signature = 'app:disable-admin {admin : Super administrator ID or email} {--reason= : Required reason for the change} {--operator= : Required operator or ticket identifier}';

    protected $description = 'Disable a super administrator and invalidate current sessions';

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

            DB::transaction(function () use ($admin, $reason, $operator): void {
                $activeSuperAdmins = User::query()
                    ->where('is_super_admin', true)
                    ->where('is_active', true)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                $locked = User::query()->whereKey($admin->id)->lockForUpdate()->firstOrFail();
                if (! $locked->is_active) {
                    throw new InvalidArgumentException('The administrator is already disabled.');
                }
                if ($activeSuperAdmins->count() <= 1) {
                    throw new InvalidArgumentException('The last active super administrator cannot be disabled.');
                }

                $locked->forceFill([
                    'is_active' => false,
                    'disabled_at' => now(),
                    'disabled_by' => null,
                    'session_version' => $locked->session_version + 1,
                    'remember_token' => null,
                ])->save();
                if (Schema::hasTable('sessions')) {
                    DB::table('sessions')->where('user_id', $locked->id)->delete();
                }

                $this->audit->record(
                    description: 'Super administrator disabled',
                    properties: ['user_id' => $locked->id, 'reason' => $reason, 'operator' => $operator],
                    subject: $locked,
                    logName: 'security',
                    event: 'admin_disabled',
                );
            });

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
