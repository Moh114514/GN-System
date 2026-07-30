<?php

namespace App\Modules\Agent\Application\Services;

use App\Modules\Agent\Application\Contracts\ConfigurationHistoryGateway;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseConfigurationHistoryGateway implements ConfigurationHistoryGateway
{
    public function __construct(private AuditRecorder $audit) {}

    public function capture(int $actorId, string $action = 'change'): int
    {
        return (int) DB::table('agent_configuration_snapshots')->insertGetId([
            'configuration_type' => 'policy_and_grade',
            'action' => $action,
            'snapshot' => json_encode($this->snapshot(), JSON_THROW_ON_ERROR),
            'created_by' => $actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function history(): array
    {
        return DB::table('agent_configuration_snapshots')->latest('id')->limit(100)->get()
            ->map(fn ($row): array => [
                'id' => (int) $row->id,
                'owner' => 'Agent',
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
                'agent_type_codes' => ['code', 'name', 'description', 'is_system', 'is_active', 'created_at', 'updated_at'],
                'policy_systems' => ['name', 'is_active', 'import_batch_id', 'created_at', 'updated_at'],
                'policy_grades' => ['policy_system_id', 'name', 'monthly_threshold_krw', 'sort_order', 'is_active', 'import_batch_id', 'created_at', 'updated_at'],
            ] as $table => $columns) {
                $rows = $target[$table] ?? [];
                if ($rows !== []) {
                    DB::table($table)->upsert($rows, ['id'], $columns);
                }
                $ids = array_column($rows, 'id');
                DB::table($table)->when($ids !== [], fn ($query) => $query->whereNotIn('id', $ids))
                    ->update(['is_active' => false]);
            }
            DB::table('agent_configuration_snapshots')->insert([
                'configuration_type' => 'policy_and_grade',
                'action' => 'rollback',
                'snapshot' => json_encode($target, JSON_THROW_ON_ERROR),
                'target_snapshot_id' => $snapshotId,
                'created_by' => $actorId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            Cache::forget('config:agent');
            $this->audit->record(
                description: '代理商政策与等级配置已回滚',
                properties: ['target_snapshot_id' => $snapshotId],
                causerId: $actorId,
                logName: 'agent-configuration',
                event: 'rolled_back',
                ipAddress: $ipAddress,
            );
        });
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    private function snapshot(): array
    {
        return [
            'agent_type_codes' => $this->rows('agent_type_codes'),
            'policy_systems' => $this->rows('policy_systems'),
            'policy_grades' => $this->rows('policy_grades'),
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
        $query = DB::table('agent_configuration_snapshots')->where('id', $snapshotId);
        $row = ($lock ? $query->lockForUpdate() : $query)->firstOrFail();

        return is_string($row->snapshot)
            ? json_decode($row->snapshot, true, 512, JSON_THROW_ON_ERROR)
            : (array) $row->snapshot;
    }

    /** @param array<string, array<int, array<string, mixed>>> $target
     *  @param array<string, array<int, array<string, mixed>>> $current
     *  @return array<string, array{changed: bool, target_count: int, current_count: int, target: array<int, array<string, mixed>>, current: array<int, array<string, mixed>>}>
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
