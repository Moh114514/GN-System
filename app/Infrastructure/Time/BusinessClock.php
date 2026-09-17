<?php

namespace App\Infrastructure\Time;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;

final class BusinessClock
{
    private const STATE_ID = 1;

    public function isAvailable(): bool
    {
        return in_array((string) config('app.deployment_environment'), ['local', 'development', 'testing', 'uat'], true)
            && (bool) config('app.time_travel_enabled');
    }

    public function isActive(): bool
    {
        return $this->activeNow() instanceof CarbonImmutable;
    }

    public function realNow(): CarbonImmutable
    {
        return CarbonImmutable::now((string) config('app.timezone'));
    }

    public function now(): CarbonImmutable
    {
        return $this->activeNow() ?? $this->realNow();
    }

    public function set(CarbonImmutable $at, ?int $changedBy = null): CarbonImmutable
    {
        $this->guardAvailable();
        $businessNow = $at->setTimezone((string) config('app.timezone'));
        $changedAt = $this->realNow();

        $this->ensureStateRow();
        DB::transaction(fn (): int => DB::table('business_clock_states')
            ->where('id', self::STATE_ID)
            ->lockForUpdate()
            ->update([
                'enabled' => true,
                'simulated_at' => $businessNow,
                'mode' => 'frozen',
                'changed_by' => $changedBy,
                'changed_at' => $changedAt,
                'updated_at' => $changedAt,
            ]));

        return $businessNow;
    }

    public function disable(?int $changedBy = null): void
    {
        $this->guardAvailable();
        $changedAt = $this->realNow();

        $this->ensureStateRow();
        DB::transaction(fn (): int => DB::table('business_clock_states')
            ->where('id', self::STATE_ID)
            ->lockForUpdate()
            ->update([
                'enabled' => false,
                'simulated_at' => null,
                'mode' => 'real',
                'changed_by' => $changedBy,
                'changed_at' => $changedAt,
                'updated_at' => $changedAt,
            ]));
    }

    public function shift(string $unit, ?int $changedBy = null): CarbonImmutable
    {
        $this->guardAvailable();
        $this->ensureStateRow();
        $changedAt = $this->realNow();

        return DB::transaction(function () use ($unit, $changedBy, $changedAt): CarbonImmutable {
            $state = DB::table('business_clock_states')
                ->where('id', self::STATE_ID)
                ->lockForUpdate()
                ->first(['enabled', 'simulated_at']);
            $base = (bool) $state?->enabled
                ? ($this->parseSimulatedTimestamp($state) ?? $this->realNow())
                : $this->realNow();
            $shifted = match ($unit) {
                'day' => $base->addDay(),
                'week' => $base->addWeek(),
                '30_days' => $base->addDays(30),
                'month' => $base->addMonthNoOverflow(),
                default => throw new LogicException('Unsupported business clock adjustment.'),
            };
            $businessNow = $shifted->setTimezone((string) config('app.timezone'));
            DB::table('business_clock_states')
                ->where('id', self::STATE_ID)
                ->update([
                    'enabled' => true,
                    'simulated_at' => $businessNow,
                    'mode' => 'frozen',
                    'changed_by' => $changedBy,
                    'changed_at' => $changedAt,
                    'updated_at' => $changedAt,
                ]);

            return $businessNow;
        });
    }

    private function guardAvailable(): void
    {
        if (! $this->isAvailable()) {
            throw new LogicException('Business time simulation is unavailable in this deployment.');
        }
    }

    private function activeNow(): ?CarbonImmutable
    {
        if (! $this->isAvailable()) {
            return null;
        }

        $state = DB::table('business_clock_states')
            ->where('id', self::STATE_ID)
            ->first(['enabled', 'simulated_at']);
        if ($state === null || ! (bool) $state->enabled) {
            return null;
        }

        return $this->parseSimulatedTimestamp($state);
    }

    private function ensureStateRow(): void
    {
        $now = $this->realNow();
        DB::table('business_clock_states')->insertOrIgnore([
            'id' => self::STATE_ID,
            'enabled' => false,
            'simulated_at' => null,
            'mode' => 'real',
            'changed_by' => null,
            'changed_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function parseSimulatedTimestamp(object $state): ?CarbonImmutable
    {
        $value = $state->simulated_at;
        if ($value === null || trim((string) $value) === '') {
            $this->logInvalidState('missing timestamp');

            return null;
        }

        try {
            return CarbonImmutable::parse($value, (string) config('app.timezone'));
        } catch (\Throwable $exception) {
            $this->logInvalidState('invalid timestamp', $exception);

            return null;
        }
    }

    private function logInvalidState(string $reason, ?\Throwable $exception = null): void
    {
        Log::warning('Business clock state is invalid; using real time.', [
            'reason' => $reason,
            'exception' => $exception?->getMessage(),
        ]);
    }
}
