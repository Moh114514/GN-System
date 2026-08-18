<?php

namespace App\Infrastructure\Time;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use LogicException;

final class BusinessClock
{
    private const ENABLED_KEY = 'gn:test-clock:enabled';

    private const NOW_KEY = 'gn:test-clock:now';

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

    public function set(CarbonImmutable $at): CarbonImmutable
    {
        $this->guardAvailable();
        $businessNow = $at->setTimezone((string) config('app.timezone'));

        Cache::forever(self::NOW_KEY, $businessNow->toIso8601String());
        Cache::forever(self::ENABLED_KEY, true);

        return $businessNow;
    }

    public function disable(): void
    {
        $this->guardAvailable();
        Cache::forget(self::ENABLED_KEY);
        Cache::forget(self::NOW_KEY);
    }

    public function shift(string $unit): CarbonImmutable
    {
        $base = $this->isActive() ? $this->now() : $this->realNow();
        $shifted = match ($unit) {
            'day' => $base->addDay(),
            'week' => $base->addWeek(),
            '30_days' => $base->addDays(30),
            'month' => $base->addMonthNoOverflow(),
            default => throw new LogicException('Unsupported business clock adjustment.'),
        };

        return $this->set($shifted);
    }

    private function guardAvailable(): void
    {
        if (! $this->isAvailable()) {
            throw new LogicException('Business time simulation is unavailable in this deployment.');
        }
    }

    private function activeNow(): ?CarbonImmutable
    {
        if (! $this->isAvailable() || ! (bool) Cache::get(self::ENABLED_KEY, false)) {
            return null;
        }

        $value = Cache::get(self::NOW_KEY);
        if (! is_string($value) || trim($value) === '') {
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
