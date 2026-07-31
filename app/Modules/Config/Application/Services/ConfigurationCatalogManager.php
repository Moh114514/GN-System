<?php

namespace App\Modules\Config\Application\Services;

use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Config\Infrastructure\Models\DictionaryItem;
use App\Modules\Config\Infrastructure\Models\Institution;
use App\Modules\Config\Infrastructure\Models\SystemParameter;
use App\Modules\Order\Application\Contracts\InstitutionUsageReader as OrderInstitutionUsageReader;
use App\Modules\Settlement\Application\Contracts\InstitutionUsageReader as SettlementInstitutionUsageReader;
use DomainException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final readonly class ConfigurationCatalogManager
{
    private const PARAMETER_RULES = [
        'report_default_per_page' => [10, 200],
        'dashboard_refresh_seconds' => [60, 3600],
    ];

    public function __construct(
        private OrderInstitutionUsageReader $orders,
        private SettlementInstitutionUsageReader $settlements,
        private AuditRecorder $audit,
    ) {}

    /** @return array<string, mixed> */
    public function state(): array
    {
        return [
            'institutions' => Institution::query()->orderBy('name')->get()->toArray(),
            'dictionary_items' => DictionaryItem::query()->orderBy('type')->orderBy('name')->get()->toArray(),
            'parameters' => SystemParameter::query()->orderBy('key')->get()->mapWithKeys(
                fn (SystemParameter $parameter): array => [
                    $parameter->key => json_decode(
                        (string) $parameter->getRawOriginal('value'),
                        true,
                        512,
                        JSON_THROW_ON_ERROR,
                    ),
                ],
            )->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function institution(int $id): array
    {
        return Institution::query()->findOrFail($id)->toArray();
    }

    /** @return array<string, mixed> */
    public function dictionaryItem(int $id): array
    {
        return DictionaryItem::query()->findOrFail($id)->toArray();
    }

    public function saveInstitution(
        ?int $id,
        string $code,
        string $name,
        ?string $address,
        ?string $contactName,
        ?string $contactValue,
        int $actorId,
        ?string $ipAddress,
    ): void {
        DB::transaction(function () use ($id, $code, $name, $address, $contactName, $contactValue, $actorId, $ipAddress): void {
            $institution = $id === null
                ? new Institution(['is_active' => true])
                : Institution::query()->lockForUpdate()->findOrFail($id);
            $before = $institution->exists ? $institution->getAttributes() : null;
            $institution->fill([
                'code' => strtoupper(trim($code)),
                'name' => trim($name),
                'address' => $this->nullable($address),
                'contact_name' => $this->nullable($contactName),
                'contact_value' => $this->nullable($contactValue),
            ])->save();
            $this->audit->record(
                description: '机构配置已保存',
                properties: ['before' => $before, 'after' => $institution->getAttributes()],
                causerId: $actorId,
                subject: $institution,
                logName: 'config',
                event: $before === null ? 'created' : 'updated',
                ipAddress: $ipAddress,
            );
        });
    }

    public function toggleInstitution(int $id, int $actorId, ?string $ipAddress): void
    {
        $institution = Institution::query()->findOrFail($id);
        $before = $institution->is_active;
        $institution->update(['is_active' => ! $institution->is_active]);
        $this->audit->record(
            description: '机构启用状态已变更',
            properties: ['before' => $before, 'after' => $institution->is_active],
            causerId: $actorId,
            subject: $institution,
            logName: 'config',
            event: 'updated',
            ipAddress: $ipAddress,
        );
    }

    public function deleteInstitution(int $id, int $actorId, ?string $ipAddress): void
    {
        DB::transaction(function () use ($id, $actorId, $ipAddress): void {
            $institution = Institution::query()->lockForUpdate()->findOrFail($id);
            if ($this->orders->institutionIsReferenced($id)
                || $this->settlements->institutionIsReferenced($id)) {
                throw new DomainException('该机构已经被业务数据引用，只能停用，不能删除。');
            }
            $this->audit->record(
                description: '未引用机构已删除',
                properties: ['before' => $institution->getAttributes()],
                causerId: $actorId,
                subject: $institution,
                logName: 'config',
                event: 'deleted',
                ipAddress: $ipAddress,
            );
            $institution->delete();
        });
    }

    public function saveDictionaryItem(
        ?int $id,
        string $type,
        string $code,
        string $name,
        int $actorId,
        ?string $ipAddress,
    ): void {
        if (! in_array($type, ['treatment_project', 'translator_language'], true)) {
            throw new DomainException('不支持的字典类型。');
        }
        $item = $id === null
            ? new DictionaryItem(['is_active' => true])
            : DictionaryItem::query()->findOrFail($id);
        $before = $item->exists ? $item->getAttributes() : null;
        $item->fill(['type' => $type, 'code' => strtoupper(trim($code)), 'name' => trim($name)])->save();
        $this->audit->record(
            description: '定向字典项已保存',
            properties: ['before' => $before, 'after' => $item->getAttributes()],
            causerId: $actorId,
            subject: $item,
            logName: 'config',
            event: $before === null ? 'created' : 'updated',
            ipAddress: $ipAddress,
        );
    }

    public function toggleDictionaryItem(int $id, int $actorId, ?string $ipAddress): void
    {
        $item = DictionaryItem::query()->findOrFail($id);
        $before = $item->is_active;
        $item->update(['is_active' => ! $item->is_active]);
        $this->audit->record(
            description: '定向字典项启用状态已变更',
            properties: ['before' => $before, 'after' => $item->is_active],
            causerId: $actorId,
            subject: $item,
            logName: 'config',
            event: 'updated',
            ipAddress: $ipAddress,
        );
    }

    public function saveParameter(string $key, int $value, int $actorId, ?string $ipAddress): void
    {
        if (! isset(self::PARAMETER_RULES[$key])) {
            throw new DomainException('该系统参数不在允许修改的白名单中。');
        }
        [$minimum, $maximum] = self::PARAMETER_RULES[$key];
        if ($value < $minimum || $value > $maximum) {
            throw new DomainException("参数值必须在 {$minimum} 至 {$maximum} 之间。");
        }
        $before = SystemParameter::query()->whereKey($key)->value('value');
        SystemParameter::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'value_type' => 'integer', 'updated_by' => $actorId],
        );
        try {
            Cache::forget("system-parameter:{$key}");
        } catch (\Throwable $exception) {
            Log::warning('系统参数缓存失效失败，参数数据库值已经更新。', [
                'key' => $key,
                'exception' => $exception->getMessage(),
            ]);
        }
        $this->audit->record(
            description: '全局系统参数已更新',
            properties: ['key' => $key, 'before' => $before, 'after' => $value],
            causerId: $actorId,
            logName: 'config',
            event: 'updated',
            ipAddress: $ipAddress,
        );
    }

    private function nullable(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
