<?php

namespace App\Modules\Settlement\Application\Contracts;

use Carbon\CarbonImmutable;

interface DirectCommissionConfigurationGateway
{
    /** @return array{current: array<string, mixed>|null, history: list<array<string, mixed>>} */
    public function configuration(CarbonImmutable $date): array;

    /** @return array<string, mixed>|null */
    public function rateForDate(CarbonImmutable $date): ?array;

    public function saveRate(
        int $rateBps,
        CarbonImmutable $effectiveFrom,
        ?CarbonImmutable $effectiveUntil,
        string $reason,
        int $actorId,
        ?string $ipAddress,
    ): void;
}
