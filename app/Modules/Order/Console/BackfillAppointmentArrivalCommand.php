<?php

namespace App\Modules\Order\Console;

use App\Modules\Order\Application\Services\LegacyAppointmentArrivalBackfill;
use App\Modules\Order\Infrastructure\Models\Appointment;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class BackfillAppointmentArrivalCommand extends Command
{
    protected $signature = 'app:backfill-appointment-arrival {appointment}
        {--arrived-at= : Verified actual arrival time, YYYY-MM-DD HH:MM:SS in Asia/Shanghai}
        {--apply : Write the verified time and an audit record}
        {--actor= : Active super administrator user ID}
        {--reason= : Evidence and reason for this historical correction}';

    protected $description = 'Preview or auditably fill one missing historical appointment arrival time';

    public function handle(LegacyAppointmentArrivalBackfill $backfill): int
    {
        try {
            $data = Validator::make([
                'appointment' => $this->argument('appointment'),
                'arrived_at' => $this->option('arrived-at'),
                'actor' => $this->option('actor'),
                'reason' => $this->option('reason'),
            ], [
                'appointment' => ['required', 'integer', 'min:1'],
                'arrived_at' => ['required', 'date_format:Y-m-d H:i:s'],
                'actor' => [$this->option('apply') ? 'required' : 'nullable', 'integer', 'min:1'],
                'reason' => [$this->option('apply') ? 'required' : 'nullable', 'string', 'max:2000'],
            ])->validate();
            $appointment = Appointment::query()->findOrFail((int) $data['appointment']);
            $this->table(['appointment_id', 'customer_id', 'institution_id', 'status', 'current_arrived_at', 'verified_arrived_at'], [[
                $appointment->id, $appointment->customer_id, $appointment->institution_id, $appointment->status,
                $appointment->arrived_at?->toIso8601String() ?? 'NULL', $data['arrived_at'].' Asia/Shanghai',
            ]]);
            if (! $this->option('apply')) {
                $this->comment('Preview only. Verify source evidence, then use --apply --actor=<id> --reason="...".');

                return self::SUCCESS;
            }
            $backfill->record($appointment->id, CarbonImmutable::parse($data['arrived_at'], 'Asia/Shanghai'), (int) $data['actor'], trim((string) $data['reason']));
            $this->info('Arrival time saved and audited.');

            return self::SUCCESS;
        } catch (DomainException|ValidationException|HttpExceptionInterface $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
