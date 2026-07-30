<?php

namespace App\Modules\Config\Application\Services;

use App\Modules\Agent\Application\Contracts\ConfigurationHistoryGateway as AgentHistory;
use App\Modules\Customer\Application\Contracts\ConfigurationHistoryGateway as CustomerHistory;
use App\Modules\Settlement\Application\Contracts\ConfigurationHistoryGateway as SettlementHistory;
use DomainException;

final readonly class ConfigurationHistoryCoordinator
{
    public function __construct(
        private AgentHistory $agent,
        private CustomerHistory $customer,
        private SettlementHistory $settlement,
    ) {}

    /** @return array<int, array<string, mixed>> */
    public function history(): array
    {
        $history = [...$this->agent->history(), ...$this->customer->history(), ...$this->settlement->history()];
        usort($history, fn (array $left, array $right): int => strcmp((string) $right['created_at'], (string) $left['created_at']));

        return $history;
    }

    /** @return array<string, array{changed: bool, target_count: int, current_count: int, target: array<int, array<string, mixed>>, current: array<int, array<string, mixed>>}> */
    public function diff(string $owner, int $snapshotId): array
    {
        return $this->gateway($owner)->diff($snapshotId);
    }

    public function rollback(string $owner, int $snapshotId, int $actorId, ?string $ipAddress): void
    {
        $this->gateway($owner)->rollback($snapshotId, $actorId, $ipAddress);
    }

    private function gateway(string $owner): AgentHistory|CustomerHistory|SettlementHistory
    {
        return match ($owner) {
            'Agent' => $this->agent,
            'Customer' => $this->customer,
            'Settlement' => $this->settlement,
            default => throw new DomainException('未知配置快照所有者。'),
        };
    }
}
