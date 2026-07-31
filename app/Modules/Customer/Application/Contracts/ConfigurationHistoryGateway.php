<?php

namespace App\Modules\Customer\Application\Contracts;

interface ConfigurationHistoryGateway
{
    public function capture(int $actorId, string $action = 'change'): int;

    /** @return array<int, array<string, mixed>> */
    public function history(): array;

    /** @return array<string, array{changed: bool, target_count: int, current_count: int, target: array<int, array<string, mixed>>, current: array<int, array<string, mixed>>}> */
    public function diff(int $snapshotId): array;

    public function rollback(int $snapshotId, int $actorId, ?string $ipAddress): void;
}
