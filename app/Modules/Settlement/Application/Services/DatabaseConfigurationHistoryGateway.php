<?php

namespace App\Modules\Settlement\Application\Services;

use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Settlement\Application\Contracts\ConfigurationHistoryGateway;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseConfigurationHistoryGateway implements ConfigurationHistoryGateway
{
    public function __construct(private AuditRecorder $audit) {}

    public function capture(int $actorId, string $action = 'change'): int
    {
        return (int) DB::table('settlement_configuration_snapshots')->insertGetId([
            'configuration_type' => 'commission_and_period',
            'action' => $action,
            'snapshot' => json_encode($this->snapshot(), JSON_THROW_ON_ERROR),
            'created_by' => $actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function history(): array
    {
        return DB::table('settlement_configuration_snapshots')->latest('id')->limit(100)->get()
            ->map(fn ($row): array => [
                'id' => (int) $row->id,
                'owner' => 'Settlement',
                'type' => (string) $row->configuration_type,
                'action' => (string) $row->action,
                'created_by' => $row->created_by === null ? null : (int) $row->created_by,
                'created_at' => (string) $row->created_at,
            ])->all();
    }

    public function diff(int $snapshotId): array
    {
        return $this->tableDiff($this->load($snapshotId), $this->snapshot());
    }

    public function rollback(int $snapshotId, int $actorId, ?string $ipAddress): void
    {
        DB::transaction(function () use ($snapshotId, $actorId, $ipAddress): void {
            $target = $this->load($snapshotId, true);
            $this->capture($actorId, 'pre_rollback');
            foreach ([
                'commission_rules' => ['policy_grade_id', 'institution_id', 'rate_bps', 'effective_month', 'is_active', 'import_batch_id', 'created_at', 'updated_at'],
                'agent_commission_overrides' => ['agent_id', 'institution_id', 'rate_bps', 'effective_from', 'effective_until', 'reason', 'approved_by', 'import_batch_id', 'created_at', 'updated_at'],
                'settlement_configurations' => ['boundary_day', 'trigger_time', 'timezone', 'effective_from', 'created_by', 'created_at', 'updated_at'],
            ] as $table => $columns) {
                $rows = $target[$table] ?? [];
                if ($rows !== []) {
                    DB::table($table)->upsert($rows, ['id'], $columns);
                }
                $ids = array_column($rows, 'id');
                DB::table($table)->when($ids !== [], fn ($query) => $query->whereNotIn('id', $ids))->delete();
            }
            DB::table('settlement_configuration_snapshots')->insert([
                'configuration_type' => 'commission_and_period',
                'action' => 'rollback',
                'snapshot' => json_encode($target, JSON_THROW_ON_ERROR),
                'target_snapshot_id' => $snapshotId,
                'created_by' => $actorId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            Cache::forget('config:settlement');
            $this->audit->record(
                description: '佣金规则与月结周期配置已回滚',
                properties: ['target_snapshot_id' => $snapshotId],
                causerId: $actorId,
                logName: 'settlement-configuration',
                event: 'rolled_back',
                ipAddress: $ipAddress,
            );
        });
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    private function snapshot(): array
    {
        return [
            'commission_rules' => $this->rows('commission_rules'),
            'agent_commission_overrides' => $this->rows('agent_commission_overrides'),
            'settlement_configurations' => $this->rows('settlement_configurations'),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function rows(string $table): array
    {
        return DB::table($table)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    private function load(int $snapshotId, bool $lock = false): array
    {
        $query = DB::table('settlement_configuration_snapshots')->where('id', $snapshotId);
        $row = ($lock ? $query->lockForUpdate() : $query)->firstOrFail();

        return is_string($row->snapshot)
            ? json_decode($row->snapshot, true, 512, JSON_THROW_ON_ERROR)
            : (array) $row->snapshot;
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $target
     * @param  array<string, array<int, array<string, mixed>>>  $current
     * @return array<string, array{changed: bool, target_count: int, current_count: int, target: array<int, array<string, mixed>>, current: array<int, array<string, mixed>>}>
     */
    private function tableDiff(array $target, array $current): array
    {
        $diff = [];
        foreach (array_unique([...array_keys($target), ...array_keys($current)]) as $table) {
            $diff[$table] = [
                'changed' => serialize($target[$table] ?? []) !== serialize($current[$table] ?? []),
                'target_count' => count($target[$table] ?? []),
                'current_count' => count($current[$table] ?? []),
                'target' => $target[$table] ?? [],
                'current' => $current[$table] ?? [],
            ];
        }

        return $diff;
    }
}
