<?php

namespace App\Modules\Auth\Console;

use App\Models\User;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class EnableAdminCommand extends Command
{
    protected $signature = 'app:enable-admin {admin : Super administrator ID or email} {--reason= : Required reason for the change} {--operator= : Required operator or ticket identifier}';

    protected $description = 'Enable a previously disabled super administrator';

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
                $locked = User::query()->whereKey($admin->id)->lockForUpdate()->firstOrFail();
                $locked->forceFill(['is_active' => true, 'disabled_at' => null, 'disabled_by' => null])->save();
                $this->audit->record(
                    description: 'Super administrator enabled',
                    properties: ['user_id' => $locked->id, 'reason' => $reason, 'operator' => $operator],
                    subject: $locked,
                    logName: 'security',
                    event: 'admin_enabled',
                );
            });

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
