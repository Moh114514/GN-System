<?php

namespace App\Modules\Customer\Application\Services;

use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Customer\Infrastructure\Models\DirectSalesSource;

final readonly class DirectSalesSourceManager
{
    public function __construct(private AuditRecorder $audit) {}

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return DirectSalesSource::query()->orderBy('name')->get()->toArray();
    }

    /** @return array<string, mixed> */
    public function find(int $id): array
    {
        return DirectSalesSource::query()->findOrFail($id)->toArray();
    }

    public function save(?int $id, string $code, string $name, int $actorId, ?string $ipAddress): void
    {
        $source = $id === null
            ? new DirectSalesSource(['is_active' => true])
            : DirectSalesSource::query()->findOrFail($id);
        $before = $source->exists ? $source->getAttributes() : null;
        $source->fill(['code' => strtoupper(trim($code)), 'name' => trim($name)])->save();
        $this->audit->record(
            description: '直销来源已保存',
            properties: ['before' => $before, 'after' => $source->getAttributes()],
            causerId: $actorId,
            subject: $source,
            logName: 'customer-configuration',
            event: $before === null ? 'created' : 'updated',
            ipAddress: $ipAddress,
        );
    }

    public function toggle(int $id, int $actorId, ?string $ipAddress): void
    {
        $source = DirectSalesSource::query()->findOrFail($id);
        $before = $source->is_active;
        $source->update(['is_active' => ! $source->is_active]);
        $this->audit->record(
            description: '直销来源启用状态已变更',
            properties: ['before' => $before, 'after' => $source->is_active],
            causerId: $actorId,
            subject: $source,
            logName: 'customer-configuration',
            event: 'updated',
            ipAddress: $ipAddress,
        );
    }
}
